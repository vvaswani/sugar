<?php
namespace App\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Psr\Log\LoggerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment as TwigEnvironment;
use Aws\S3\S3Client;
use App\Message\GenerateUserReportMessage;
use App\Entity\Report;
use App\Entity\Reading;
use App\Entity\User;

#[AsMessageHandler]
class GenerateUserReportMessageHandler
{
    public function __construct(
        private EntityManagerInterface $em,
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private TwigEnvironment $twig,
        private ParameterBagInterface $params,
        private S3Client $s3Client
    ) {}

    public function __invoke(GenerateUserReportMessage $message): void
    {
        $userId = $message->getUserId();
        $startDate = $message->getStartDate();
        $endDate = $message->getEndDate();
        $reportType = $message->getReportType();

        if ($reportType === GenerateUserReportMessage::TYPE_WEEKLY) {
            $this->generateWeeklyReport($userId, $startDate, $endDate);
        } elseif ($reportType === GenerateUserReportMessage::TYPE_DAILY) {
            $this->generateDailyReport($userId, $startDate, $endDate);
        }
    }

    private function generateWeeklyReport(int $userId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): void
    {
        $user = $this->em->getRepository(User::class)->find($userId);
        $timezone = new \DateTimeZone($user->getTimezone());
        $startDateInUserTz = (clone $startDate)->setTimezone($timezone);
        $endDateInUserTz = (clone $endDate)->modify('-1 day')->setTimezone($timezone);

        $existingReport = $this->em->getRepository(Report::class)->findOneBy([
            'user' => $userId,
            'date' => $startDateInUserTz,
            'type' => Report::TYPE_WEEKLY,
        ]);

        if ($existingReport) {
            return;
        }

        $qb = $this->em->getRepository(Reading::class)->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt < :end')
            ->orderBy('r.createdAt', 'ASC');
        $qb->setParameter('user', $userId);
        $qb->setParameter('start', $startDate);
        $qb->setParameter('end', $endDate);
        $readings = $qb->getQuery()->getResult();

        if (count($readings) === 0) {
            return;
        }

        $values = array_map(fn($r) => $r->getValue(), $readings);
        $average = array_sum($values) / count($values);

        $user = $this->em->getRepository(User::class)->find($userId);
        $timezone = new \DateTimeZone($user->getTimezone());

        $convertedReadings = array_map(function (Reading $reading) use ($timezone) {
            $convertedCreatedAt = (clone $reading->getCreatedAt())->setTimezone($timezone);
            return (object)[
                'createdAt' => $convertedCreatedAt,
                'value' => $reading->getValue(),
                'type' => $reading->getType(),
            ];
        }, $readings);

        $typeLabel = fn($type) => match ($type) {
            Reading::TYPE_FASTING => 'Fasting',
            Reading::TYPE_POST_PRANDIAL => 'Post-prandial',
            Reading::TYPE_RANDOM => 'Random',
            default => 'Random',
        };

        $readingSummary = implode(', ', array_map(function ($r) use ($typeLabel) {
            return sprintf(
                '%s: %.1f',
                $r->createdAt->format('d M Y H:i'),
                $r->value,
                $typeLabel($r->type)
            );
        }, $convertedReadings));

        $geminiAnalysis = 'Analysis unavailable.';
        try {
            $response = $this->httpClient->request('POST', 'https://generativelanguage.googleapis.com/v1/models/gemini-2.5-flash:generateContent?key=' . $_ENV['GEMINI_API_KEY'], [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'contents' => [[
                        'parts' => [[
                            'text' => "Return your response in plain text only. Do not use Markdown, asterisks (*), underscores (_), bolding, italics, or any special formatting. Analyze these glucose readings (fasting, post-prandial and random) over the past week:\n\n" . $readingSummary
                        ]]
                    ]],
                ]
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);

            if ($statusCode === 200) {
                $data = json_decode($content, true);
                $geminiAnalysis = $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Analysis not returned.';
            } else {
                $this->logger->error('Gemini API failed', ['response' => $content]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Gemini API exception', ['exception' => $e]);
        }

        $html = $this->twig->render('weekly-report.html.twig', [
            'name' => $user->getName(),
            'startDate' => $startDateInUserTz->format('d M Y'),
            'endDate' => $endDateInUserTz->format('d M Y'),
            'readings' => $convertedReadings,
            'timezone' => $user->getTimezone(),
            'average' => round($average, 2),
            'analysis' => $geminiAnalysis,
        ]);

        $filename = sprintf(
            'report_user%d_%s_to_%s.pdf',
            $userId,
            $startDate->format('Ymd'),
            (clone $endDate)->modify('-1 day')->format('Ymd')
        );

        $path = $this->params->get('kernel.project_dir') . '/data/' . $filename;

        $dompdf = new Dompdf((new Options())->set('defaultFont', 'DejaVu Sans'));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        $bucket = $_ENV['AWS_BUCKET'];
        $s3Key = 'reports/' . $filename;

        $this->s3Client->putObject([
            'Bucket' => $bucket,
            'Key' => $s3Key,
            'Body' => $pdfContent,
            'ContentType' => 'application/pdf',
        ]);

        $report = new Report();
        $report->setUser($user);
        $report->setDate($startDateInUserTz);
        $report->setType(Report::TYPE_WEEKLY);
        $report->setFilename($filename);

        $this->em->persist($report);
        $this->em->flush();
    }


    private function generateDailyReport(int $userId, \DateTimeInterface $startDate, \DateTimeInterface $endDate): void
    {
        $user = $this->em->getRepository(User::class)->find($userId);
        $timezone = new \DateTimeZone($user->getTimezone());
        $startDateInUserTz = (clone $startDate)->setTimezone($timezone);

        $existingReport = $this->em->getRepository(Report::class)->findOneBy([
            'user' => $userId,
            'date' => $startDateInUserTz,
            'type' => Report::TYPE_DAILY,
        ]);

        if ($existingReport) {
            return;
        }

        $qb = $this->em->getRepository(Reading::class)->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.createdAt >= :start')
            ->andWhere('r.createdAt < :end')
            ->orderBy('r.createdAt', 'ASC');
        $qb->setParameter('user', $userId);
        $qb->setParameter('start', $startDate);
        $qb->setParameter('end', $endDate);
        $readings = $qb->getQuery()->getResult();

        if (count($readings) === 0) {
            return;
        }

        $values = array_map(fn($r) => $r->getValue(), $readings);
        $average = array_sum($values) / count($values);

        $convertedReadings = array_map(function (Reading $r) use ($timezone) {
            $createdAt = (clone $r->getCreatedAt())->setTimezone($timezone);
            return (object) [
                'createdAt' => $createdAt,
                'value' => $r->getValue(),
                'type' => $r->getType(),
            ];
        }, $readings);

        $html = $this->twig->render('daily-report.html.twig', [
            'name' => $user->getName(),
            'date' => $startDateInUserTz->format('Y-m-d'),
            'readings' => $convertedReadings,
            'timezone' => $user->getTimezone(),
            'average' => round($average, 2),
        ]);

        $filename = sprintf('report_user%d_%s.pdf', $userId, $startDate->format('Ymd'));
        $path = $this->params->get('kernel.project_dir') . '/data/' . $filename;

        $dompdf = new Dompdf((new Options())->set('defaultFont', 'DejaVu Sans'));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdfContent = $dompdf->output();

        $bucket = $_ENV['AWS_BUCKET'];
        $s3Key = 'reports/' . $filename;

        if (!$this->s3Client->doesBucketExist($bucket)) {
            $this->s3Client->createBucket(['Bucket' => $bucket]);
        }

        $this->s3Client->putObject([
            'Bucket' => $bucket,
            'Key' => $s3Key,
            'Body' => $pdfContent,
            'ContentType' => 'application/pdf',
        ]);

        $report = new Report();
        $report->setUser($user);
        $report->setDate($startDateInUserTz);
        $report->setType(Report::TYPE_DAILY);
        $report->setFilename($filename);

        $this->em->persist($report);
        $this->em->flush();
    }
}
