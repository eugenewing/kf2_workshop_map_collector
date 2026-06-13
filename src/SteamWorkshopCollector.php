<?php

declare(strict_types=1);

final class SteamWorkshopCollector
{
    private HttpClient $httpClient;

    public function __construct(?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient();
    }

    /**
     * @param null|callable(array<string, mixed>):void $onProgress
     * @return array{
     *   maps:array<int, array{id:string, name:string}>,
     *   review:array<int, array{id:string, name:string}>,
     *   state:array<string, mixed>
     * }
     */
    public function collectAll(?int $maxBrowsePages = null, int $delayMs = KF2_WSMC_DEFAULT_DELAY_MS, ?callable $onProgress = null): array
    {
        $reportProgress = static function (?callable $callback, array $state): void {
            if ($callback !== null) {
                $callback($state);
            }
        };

        $browseResult = $this->collectWorkshopItems($maxBrowsePages, $delayMs, static function (array $browseState) use ($reportProgress, $onProgress): void {
            $reportProgress($onProgress, [
                'phase' => 'browse',
                'workshop_total_items' => $browseState['total_entries'] ?? 0,
                'browse_pages_processed' => $browseState['pages_processed'] ?? 0,
                'browse_pages_limit' => $browseState['page_limit'] ?? null,
            ]);
        });

        $details = $this->fetchPublishedFileDetails(array_column($browseResult['items'], 'id'), $delayMs, static function (array $detailsState) use ($reportProgress, $onProgress): void {
            $reportProgress($onProgress, [
                'phase' => 'details',
                'detailed_items_analyzed' => $detailsState['detailed_items_analyzed'] ?? 0,
            ]);
        });

        $maps = [];
        $review = [];

        $totalDetails = count($details);
        foreach ($details as $index => $item) {
            $analysis = $this->analyzeCandidate($item);

            if ($analysis['is_likely_map']) {
                $maps[] = [
                    'id' => $analysis['id'],
                    'name' => $analysis['name'],
                ];
            } elseif ($analysis['is_suspicious']) {
                $review[] = [
                    'id' => $analysis['id'],
                    'name' => $analysis['name'],
                ];
            }

            if (($index + 1) % 25 === 0 || $index + 1 === $totalDetails) {
                $reportProgress($onProgress, [
                    'phase' => 'classify',
                    'detailed_items_analyzed' => $index + 1,
                    'maps_count' => count($maps),
                    'review_count' => count($review),
                ]);
            }
        }

        usort($maps, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));
        usort($review, static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name']));

        return [
            'maps' => $maps,
            'review' => $review,
            'state' => [
                'phase' => 'done',
                'last_run_at' => gmdate('c'),
                'workshop_total_items' => $browseResult['total_entries'],
                'detailed_items_analyzed' => count($details),
                'maps_count' => count($maps),
                'review_count' => count($review),
                'browse_pages_processed' => $browseResult['page_limit'],
                'browse_pages_limit' => $browseResult['page_limit'],
                'status' => 'ok',
                'error' => null,
            ],
        ];
    }

    /**
     * @param null|callable(array<string, mixed>):void $onProgress
     * @return array{total_entries:int, page_limit:int, items:array<int, array{id:string, name:string}>}
     */
    private function collectWorkshopItems(?int $maxBrowsePages, int $delayMs, ?callable $onProgress = null): array
    {
        $firstPageHtml = $this->fetchText($this->buildBrowseUrl(1));
        $totalEntries = $this->parseTotalEntries($firstPageHtml);
        $totalPages = max(1, (int) ceil($totalEntries / KF2_WSMC_PAGE_SIZE));
        $pageLimit = $maxBrowsePages === null ? $totalPages : min($totalPages, $maxBrowsePages);
        $itemsById = [];

        foreach ($this->parseItemsFromBrowsePage($firstPageHtml) as $item) {
            $itemsById[$item['id']] = $item;
        }

        if ($onProgress !== null) {
            $onProgress([
                'total_entries' => $totalEntries,
                'pages_processed' => 1,
                'page_limit' => $pageLimit,
            ]);
        }

        for ($pageNumber = 2; $pageNumber <= $pageLimit; $pageNumber++) {
            $this->pause($delayMs);
            $pageHtml = $this->fetchText($this->buildBrowseUrl($pageNumber));

            foreach ($this->parseItemsFromBrowsePage($pageHtml) as $item) {
                $itemsById[$item['id']] = $item;
            }

            if ($onProgress !== null) {
                $onProgress([
                    'total_entries' => $totalEntries,
                    'pages_processed' => $pageNumber,
                    'page_limit' => $pageLimit,
                ]);
            }
        }

        return [
            'total_entries' => $totalEntries,
            'page_limit' => $pageLimit,
            'items' => array_values($itemsById),
        ];
    }

    /**
     * @param array<int, string> $ids
     * @param null|callable(array<string, mixed>):void $onProgress
     * @return array<int, array<string, mixed>>
     */
    private function fetchPublishedFileDetails(array $ids, int $delayMs, ?callable $onProgress = null): array
    {
        $details = [];
        $chunks = array_chunk($ids, KF2_WSMC_API_BATCH_SIZE);

        if ($onProgress !== null) {
            $onProgress([
                'detailed_items_analyzed' => 0,
            ]);
        }

        foreach ($chunks as $index => $chunk) {
            if ($index > 0) {
                $this->pause($delayMs);
            }

            $form = ['itemcount' => (string) count($chunk)];
            foreach ($chunk as $itemIndex => $id) {
                $form["publishedfileids[$itemIndex]"] = $id;
            }

            $response = $this->httpClient->postForm(KF2_WSMC_DETAILS_URL, $form);
            $decoded = json_decode($response['body'], true);
            $publishedItems = $decoded['response']['publishedfiledetails'] ?? [];

            foreach ($publishedItems as $item) {
                if ((int) ($item['consumer_app_id'] ?? 0) !== KF2_WSMC_APP_ID) {
                    continue;
                }
                if ((int) ($item['result'] ?? 0) !== 1) {
                    continue;
                }

                $details[] = $item;
            }

            if ($onProgress !== null) {
                $onProgress([
                    'detailed_items_analyzed' => count($details),
                ]);
            }
        }

        return $details;
    }

    private function buildBrowseUrl(int $pageNumber): string
    {
        $params = http_build_query([
            'appid' => KF2_WSMC_APP_ID,
            'section' => 'readytouseitems',
            'browsefilter' => 'mostrecent',
            'browsesort' => 'mostrecent',
            'actualsort' => 'mostrecent',
            'p' => $pageNumber,
            'numperpage' => KF2_WSMC_PAGE_SIZE,
        ], '', '&', PHP_QUERY_RFC3986);

        return KF2_WSMC_BROWSE_URL . '?' . $params;
    }

    private function fetchText(string $url): string
    {
        $response = $this->httpClient->get($url);
        return $response['body'];
    }

    private function parseTotalEntries(string $html): int
    {
        $patterns = [
            '/Showing\s+\d+\s*-\s*\d+\s+of\s+([\d,]+)\s+entries/i',
            '/([\d,]+)\s+entries\s+matching\s+filters/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches) === 1) {
                return (int) str_replace(',', '', $matches[1]);
            }
        }

        throw new RuntimeException('Unable to determine total entry count from the workshop browse page.');
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    private function parseItemsFromBrowsePage(string $html): array
    {
        $itemsById = [];
        $pattern = '/SharedFileBindMouseHover\(\s*"sharedfile_\d+"\s*,\s*false\s*,\s*(\{[\s\S]*?"appid":232090\})\s*\);/i';

        if (preg_match_all($pattern, $html, $matches) !== false) {
            foreach ($matches[1] as $payloadJson) {
                $payload = json_decode($payloadJson, true);
                if (!is_array($payload)) {
                    continue;
                }

                $id = trim((string) ($payload['id'] ?? ''));
                $name = trim((string) ($payload['title'] ?? ''));

                if ($id === '' || $name === '') {
                    continue;
                }

                $itemsById[$id] = [
                    'id' => $id,
                    'name' => $name,
                ];
            }
        }

        return array_values($itemsById);
    }

    /**
     * @param array<string, mixed> $item
     * @return array{id:string, name:string, is_likely_map:bool, is_suspicious:bool}
     */
    private function analyzeCandidate(array $item): array
    {
        $name = $this->normalizeText((string) ($item['title'] ?? ''));
        $description = $this->normalizeText((string) ($item['description'] ?? ''));
        $rawText = $name . "\n" . $description;
        $cleanedText = $this->stripNonContentNoise($rawText);
        $text = mb_strtolower($cleanedText);
        $tags = $this->tagSet($item);

        $score = 0;
        $hasCdeditInTitle = preg_match('/\bcdedit\b/i', $name) === 1;
        $hasTitleKfPrefix = preg_match('/^\s*kf-/i', $name) === 1;
        $hasAnyKfPrefix = preg_match('/\bkf-[a-z0-9]/i', $text) === 1;
        $hasMapNameKf = preg_match('/\bmapname\s*=\s*kf-[a-z0-9]/i', $text) === 1;
        $hasKfmFileMarker = preg_match('/\.kfm\b/i', $text) === 1;
        $hasFileNameMarker = preg_match('/\bfile\s*name\s*:/i', $text) === 1;
        $hasMapNameMarker = preg_match('/\bmap\s*name\s*:/i', $text) === 1;
        $hasOriginalMapMarker = preg_match('/\boriginal\s+map\s*:/i', $text) === 1;
        $hasKf1Reference = preg_match('/\bkf1(?:-|\b)/i', $text) === 1 || preg_match('/\bkilling floor 1\b/i', $text) === 1;
        $hasMapWord = preg_match('/\bmap\b|\bmaps\b/i', $text) === 1;
        $hasMapPhrase = preg_match('/\b(fun|custom|small|medium|large|objective|holdout|survival|arena|slaughter|progression|defensive|playable|coop)\s+map\b/i', $text) === 1
            || preg_match('/\bthis map\b|\ba map\b/i', $text) === 1;
        $hasGameplayTerms = preg_match('/\btrader\b|\bzed\b|\bwaves?\b|\bspawn(?:s|ed|ing)?\b|\bendless\b|\bobjective\b|\bholdout\b|\bsurvival\b/i', $text) === 1;
        $hasRemakeSignal = preg_match('/\bremake\b|\bported\b|\bport\b/i', $text) === 1;
        $hasMapsTag = isset($tags['maps']);
        $hasGamemodeTag = isset($tags['gamemode']) || isset($tags['gamemodes']) || isset($tags['outbreak']) || isset($tags['weekly outbreak']);
        $hasMutatorCommand = preg_match('/\?mutator=|\bmutator=/i', $rawText) === 1;

        $hasAudioTerms = preg_match('/\bmusic\b|\bsoundtrack\b|\baudio\b|\bsong\b|\bost\b|\bmenu music\b|\bambience\b|\bambient music\b/i', $text) === 1;
        $hasCosmeticTerms = preg_match('/\bskin\b|\bcosmetic\b|\bcharacter\b|\bmodel\b|\bvoice pack\b|\bannouncer\b|\bhud\b|\bui\b/i', $text) === 1;
        $hasModOnlyTerms = preg_match('/\bmutator\b|\bgameplay mod\b|\boverhaul\b|\bserver tool\b|\bplugin\b|\bweapon pack\b/i', $text) === 1;
        $hasGamemodeTerms = preg_match('/\bweekly\s+outbreak\b|\boutbreak\b|\bgame\s*mode\b|\bgamemode\b|\bgamemodes\b|\bmode\s+only\b/i', $text) === 1;
        $hasModReferenceTerms = preg_match('/\bthis\s+mod\b|\bmodification\b|\bmodifications\b/i', $text) === 1;
        $hasModeInText = preg_match('/\bmode\b|[a-z0-9]+modes?\b/i', $text) === 1;
        $hasUtilityTerms = preg_match('/\bradar\b|\bcrosshair\b|\bwhitelist(?:ed)?\b|\btournament\b/i', $text) === 1;
        $hasStrongMapMarkers = $hasMapNameKf || $hasKfmFileMarker || $hasFileNameMarker || $hasMapNameMarker || $hasOriginalMapMarker;

        if ($hasCdeditInTitle) {
            $score += 4;
        }
        if ($hasMapNameKf) {
            $score += 8;
        }
        if ($hasKfmFileMarker) {
            $score += 5;
        }
        if ($hasFileNameMarker) {
            $score += 3;
        }
        if ($hasMapNameMarker) {
            $score += 4;
        }
        if ($hasOriginalMapMarker) {
            $score += 5;
        }
        if ($hasTitleKfPrefix) {
            $score += 6;
        } elseif ($hasAnyKfPrefix) {
            $score += 4;
        }
        if ($hasKf1Reference) {
            $score += 3;
        }
        if ($hasMapsTag) {
            $score += 3;
        }
        if ($hasMapPhrase) {
            $score += 3;
        } elseif ($hasMapWord) {
            $score += 2;
        }
        if ($hasGameplayTerms) {
            $score += 2;
        }
        if ($hasRemakeSignal && ($hasAnyKfPrefix || $hasKf1Reference || $hasMapWord)) {
            $score += 1;
        }

        if ($hasAudioTerms) {
            $score -= 5;
        }
        if ($hasCosmeticTerms) {
            $score -= 5;
        }
        if ($hasModOnlyTerms && !$hasMapNameKf && !$hasTitleKfPrefix) {
            $score -= 4;
        }
        if ($hasGamemodeTerms) {
            $score -= 6;
        }
        if ($hasGamemodeTag) {
            $score -= 6;
        }
        if ($hasModeInText && !$hasStrongMapMarkers) {
            $score -= 3;
        }
        if ($hasModReferenceTerms && !$hasMapNameKf && !$hasMapPhrase && !$hasMapsTag) {
            $score -= 3;
        }
        if ($hasMutatorCommand) {
            $score -= 6;
        }
        if ($hasUtilityTerms && !$hasMapsTag && !$hasMapWord && !$hasMapPhrase) {
            $score -= 4;
        }

        $positiveSignals = count(array_filter([
            $hasCdeditInTitle,
            $hasMapNameKf,
            $hasKfmFileMarker || $hasFileNameMarker || $hasMapNameMarker || $hasOriginalMapMarker,
            $hasTitleKfPrefix || $hasAnyKfPrefix,
            $hasKf1Reference,
            $hasMapsTag,
            $hasMapWord || $hasMapPhrase,
            $hasGameplayTerms,
        ]));

        $hasStrongNegative = $hasAudioTerms || $hasCosmeticTerms || $hasGamemodeTerms || $hasGamemodeTag;

        $isLikelyMap =
            ($hasMapNameKf && !$hasStrongNegative) ||
            ($score >= 7 && $positiveSignals >= 2) ||
            ($score >= 5 && ($hasTitleKfPrefix || $hasAnyKfPrefix) && ($hasMapWord || $hasMapPhrase || $hasGameplayTerms));

        $isSuspicious =
            !$isLikelyMap &&
            !$hasStrongNegative &&
            (
                ($score >= 3 && $positiveSignals >= 2) ||
                (($hasMapWord || $hasMapPhrase) && ($hasMapsTag || $hasGameplayTerms || $hasKf1Reference)) ||
                (($hasTitleKfPrefix || $hasAnyKfPrefix) && $score >= 2)
            );

        return [
            'id' => (string) ($item['publishedfileid'] ?? ''),
            'name' => $name,
            'is_likely_map' => $isLikelyMap,
            'is_suspicious' => $isSuspicious,
        ];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, true>
     */
    private function tagSet(array $item): array
    {
        $result = [];
        foreach ($item['tags'] ?? [] as $tag) {
            $name = trim(mb_strtolower((string) ($tag['tag'] ?? '')));
            if ($name !== '') {
                $result[$name] = true;
            }
        }
        return $result;
    }

    private function normalizeText(string $value): string
    {
        $value = str_replace("\r", "\n", $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function stripNonContentNoise(string $text): string
    {
        $text = preg_replace('/https?:\/\/\S+/i', ' ', $text) ?? $text;
        $text = preg_replace('/\bopen\s+kf-[^\n\r?]*\?mutator=[^\n\r]*/i', ' ', $text) ?? $text;
        $text = preg_replace('/\[[^\]]+\]/', ' ', $text) ?? $text;
        return $text;
    }

    private function pause(int $delayMs): void
    {
        if ($delayMs <= 0) {
            return;
        }

        usleep($delayMs * 1000);
    }
}
