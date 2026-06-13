<?php

declare(strict_types=1);

const KF2_WSMC_APP_NAME = 'KF2_WSMC';
const KF2_WSMC_APP_ID = 232090;
const KF2_WSMC_PAGE_SIZE = 30;
const KF2_WSMC_API_BATCH_SIZE = 100;
const KF2_WSMC_BROWSE_URL = 'https://steamcommunity.com/workshop/browse/';
const KF2_WSMC_DETAILS_URL = 'https://api.steampowered.com/ISteamRemoteStorage/GetPublishedFileDetails/v1/';
const KF2_WSMC_DEFAULT_DELAY_MS = 40;

function kf2_wsmc_root_path(string $suffix = ''): string
{
    $root = dirname(__DIR__);

    if ($suffix === '') {
        return $root;
    }

    return $root . DIRECTORY_SEPARATOR . ltrim($suffix, DIRECTORY_SEPARATOR);
}

function kf2_wsmc_data_path(string $filename): string
{
    return kf2_wsmc_root_path('data' . DIRECTORY_SEPARATOR . $filename);
}

