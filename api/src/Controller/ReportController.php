<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Psr\Log\LoggerInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Repository\ReportRepository;
use App\Message\GenerateUserReportMessage;
use App\Entity\Reading;
use App\Entity\Report;
use App\Entity\User;

#[AsController]
class ReportController extends AbstractController
{
    private EntityManagerInterface $em;
    private Security $security;
    private S3Client $s3Client;

    public function __construct(Security $security, EntityManagerInterface $em, HttpClientInterface $httpClient, LoggerInterface $logger, S3Client $s3Client) {
        $this->security = $security;
        $this->em = $em;
        $this->s3Client = $s3Client;
    }

    #[Route('/generate-daily-reports', name: 'generate_daily_reports')]
    public function generateDailyReports(MessageBusInterface $bus): Response
    {
        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
        $users = $this->em->getRepository(User::class)->findAll();

        foreach ($users as $user) {
            $timezone = new \DateTimeZone($user->getTimezone());

            // Calculate user's current local time
            $nowInUserTz = (clone $nowUtc)->setTimezone($timezone);

            // Compute "yesterday" in user's local timezone
            $localStart = new \DateTime('yesterday', $timezone);
            $localStart->setTime(0, 0);
            $localEnd = (clone $localStart)->modify('+1 day');

            // If the user's current local time is before the end of yesterday (i.e., today hasn't started yet), skip
            if ($nowInUserTz < $localEnd) {
                continue; // too early to generate report for this user
            }

            // Convert local start/end to UTC for DB querying
            $utcStart = (clone $localStart)->setTimezone(new \DateTimeZone('UTC'));
            $utcEnd = (clone $localEnd)->setTimezone(new \DateTimeZone('UTC'));

            // Dispatch message with the UTC date range
            $bus->dispatch(new GenerateUserReportMessage($user->getId(), $utcStart, $utcEnd, GenerateUserReportMessage::TYPE_DAILY));
        }

        return new Response('Daily reports enqueued.');
    }

    #[Route('/generate-weekly-reports', name: 'generate_weekly_reports')]
    public function generateWeeklyReports(MessageBusInterface $bus): Response
    {

        $nowUtc = new \DateTime('now', new \DateTimeZone('UTC'));
        $users = $this->em->getRepository(User::class)->findAll();

        foreach ($users as $user) {
            $timezone = new \DateTimeZone($user->getTimezone());
            $nowInUserTz = (clone $nowUtc)->setTimezone($timezone);

            // Only generate weekly reports if it's Monday (00:00+) in user's local time
            //if ((int)$nowInUserTz->format('N') !== 1 || (int)$nowInUserTz->format('H') < 0) {
            //    continue; // too early to generate weekly report
            //}

            // Determine user's last week's range in local time (Monday to Sunday)
            $localStart = new \DateTime('last monday', $timezone);
            $localStart->modify('-7 days')->setTime(0, 0); // start of last week
            $localEnd = (clone $localStart)->modify('+7 days'); // end of last week

            // Convert to UTC for querying readings
            $utcStart = (clone $localStart)->setTimezone(new \DateTimeZone('UTC'));
            $utcEnd = (clone $localEnd)->setTimezone(new \DateTimeZone('UTC'));

            $bus->dispatch(new GenerateUserReportMessage(
                $user->getId(),
                $utcStart,
                $utcEnd,
                GenerateUserReportMessage::TYPE_WEEKLY
            ));
        }

        return new Response('Weekly reports enqueued.');
    }

    #[Route('/api/reports', name: 'api_get_reports', methods: ['GET'])]
    public function getReports(ReportRepository $reportRepository): Response
    {
        $user = $this->security->getUser();
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }
        $reports = $reportRepository->findBy(['user' => $user],  ['date' => 'DESC']);
        return $this->json($reports);
    }

    #[Route('/download-report/{filename}', name: 'download_report')]
    public function downloadReport(string $filename): Response
    {
        $bucket = $_ENV['AWS_BUCKET'];
        $s3Key = 'reports/' . $filename;

        try {
            $result = $this->s3Client->getObject([
                'Bucket' => $bucket,
                'Key' => $s3Key,
            ]);

            $bodyStream = $result['Body'];

            return new StreamedResponse(function () use ($bodyStream) {
                while (!$bodyStream->eof()) {
                    echo $bodyStream->read(1024);
                    flush();
                }
            }, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="' . $filename . '"',
            ]);
        } catch (S3Exception $e) {
            throw $this->createNotFoundException('Report not found');
        }
    }

}
