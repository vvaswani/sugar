<?php

namespace App\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
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
