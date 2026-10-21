<?php

declare(strict_types=1);

/**
 * Reusable Print Header
 *
 * Usage:
 *   $printTitle = 'Student List';
 *   $printSubtitle = 'Filtered by: Class 5A, Term 1, Session 2024/2025';
 *   require __DIR__ . '/print-header.php';
 *
 * Available variables:
 *   $printTitle (string) - Main title for the printout
 *   $printSubtitle (string|null) - Optional subtitle with filter info
 *   $showLogo (bool) - Whether to show school logo (default true)
 *   $logoPath (string) - Custom logo path (optional)
 *   $landscape (bool) - Whether to use landscape orientation (default false)
 */

$printTitle = $printTitle ?? 'Report';
$printSubtitle = $printSubtitle ?? null;
$showLogo = $showLogo ?? true;
$logoPath = $logoPath ?? null;
$landscape = $landscape ?? false;

require_once __DIR__ . '/../config/functions.php';
$settings = getSchoolSettings();
$schoolName = $settings['school_name'] ?? 'School Name';
$schoolShortName = $settings['school_short_name'] ?? 'SCHOOL';
$schoolCampaign = $settings['school_campaign'] ?? '';
$schoolAddress = $settings['school_address'] ?? '';
$schoolPhone = $settings['school_phone'] ?? '';
$schoolEmail = $settings['school_email'] ?? '';
$logoUrl = $logoPath ?? ($settings['logo_path'] ?? 'assets/images/logo.jpeg');
$logoUrl = assetUrl($logoUrl);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($printTitle) ?> | <?= e($schoolShortName) ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= e(assetUrl('assets/css/print.css')) ?>" rel="stylesheet">
    <style>
        @page {
            size: A4 <?= $landscape ? 'landscape' : 'portrait' ?>;
            margin: 15mm;
        }
    </style>
</head>
<body class="print-body">

<div class="print-toolbar no-print">
    <button class="btn btn-green btn-sm" type="button" onclick="window.print()">
        <i class="bi bi-printer-fill"></i> Print / Save as PDF
    </button>
    <span class="text-muted small">
        A4, <?= $landscape ? 'Landscape' : 'Portrait' ?>, Margins: 15mm
    </span>
</div>

<div class="print-header" style="border-bottom: 2px solid #0f5132; padding-bottom: 12px; margin-bottom: 15px; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
    <?php if ($showLogo): ?>
    <div style="display: flex; align-items: center; gap: 14px;">
        <img src="<?= e($logoUrl) ?>" alt="<?= e($schoolShortName) ?>" style="width: 58px; height: 58px; object-fit: contain; flex: none; background: #fff; border-radius: 50%; padding: 2px; border: 1px solid #e2e8e4;">
        <div style="flex: 1; min-width: 0;">
            <h1 style="margin: 0; font-size: 13pt; font-weight: 700; line-height: 1.2; color: #0b3d2c; text-transform: uppercase; letter-spacing: 0.2px;"><?= e($schoolName) ?></h1>
            <?php if ($schoolCampaign): ?>
            <div style="font-size: 8pt; font-weight: 600; color: #d4a017; margin: 2px 0 0; letter-spacing: 0.4px;"><?= e($schoolCampaign) ?></div>
            <?php endif; ?>
            <div style="font-size: 7pt; color: #5b6b60; margin: 2px 0 0; line-height: 1.4;">
                <?php if ($schoolAddress): ?>
                    <strong><?= e($schoolAddress) ?></strong><br>
                <?php endif; ?>
                <?php if ($schoolPhone): ?>
                    <strong>Phone:</strong> <?= e($schoolPhone) ?><?php if ($schoolEmail): ?> | <?php endif; ?>
                <?php endif; ?>
                <?php if ($schoolEmail): ?>
                    <strong>Email:</strong> <?= e($schoolEmail) ?>
                <?php endif; ?>
            </div>
        </div>
        <div style="text-align: right; font-size: 7pt; color: #5b6b60; min-width: 100px;">
            <strong style="display: block; font-size: 10pt; letter-spacing: 1.4px; color: #0f5132;"><?= e($schoolShortName) ?></strong>
            <div><?= date('d M Y') ?></div>
            <div><?= date('H:i') ?></div>
        </div>
    </div>
    <?php else: ?>
    <div style="text-align: center;">
        <h1 style="margin: 0 0 4px; font-size: 14pt; font-weight: 700; color: #0b3d2c; text-transform: uppercase;"><?= e($schoolName) ?></h1>
        <?php if ($schoolCampaign): ?>
        <div style="font-size: 8pt; font-weight: 600; color: #d4a017; margin-bottom: 4px;"><?= e($schoolCampaign) ?></div>
        <?php endif; ?>
        <div style="font-size: 7pt; color: #5b6b60; margin-bottom: 4px;">
            <?= e(implode(' | ', array_filter([$schoolAddress, $schoolPhone, $schoolEmail]))) ?>
        </div>
        <div style="font-size: 7pt; color: #5b6b60;"><?= e($schoolShortName) ?> | <?= date('d M Y H:i') ?></div>
    </div>
    <?php endif; ?>
</div>

<div class="print-titlebar" style="margin-bottom: 12px; border: 1px solid #1a5f7a; border-left: 5px solid #157347; border-radius: 2px; background: linear-gradient(90deg, #f2f8fa 0%, #fff 70%); padding: 8px 12px; text-align: center; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
    <div style="font-size: 11pt; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: #0b3d2c; margin: 0;"><?= e($printTitle) ?></div>
    <?php if ($printSubtitle): ?>
    <div style="font-size: 7.5pt; color: #1a5f7a; margin: 3px 0 0; letter-spacing: 0.3px;"><?= e($printSubtitle) ?></div>
    <?php endif; ?>
</div>