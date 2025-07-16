<?php
// utils/pdf_converter.php

require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

function convert_html_to_pdf(string $htmlContent, string $filename): string
{
    $outputsDir = __DIR__ . '/../outputs';
    if (!is_dir($outputsDir)) {
        mkdir($outputsDir, 0777, true);
    }

    $options = new Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($htmlContent);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $filePath = $outputsDir . '/' . $filename;
    file_put_contents($filePath, $dompdf->output());

    return $filePath;
}

// To test this utility directly from the command line:
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    echo "Testing PDF Converter...\n";
    $sampleHtml = '<html><body><h1>Hello, World!</h1><p>This is a test PDF from dompdf.</p></body></html>';
    $outputFile = 'test_cv.pdf';
    try {
        $savedPath = convert_html_to_pdf($sampleHtml, $outputFile);
        echo "PDF successfully created at: " . $savedPath . "\n";
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

