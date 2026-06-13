<?php

declare(strict_types=1);

final class JsonStorage
{
    /**
     * @param array<int, array{id:string, name:string}> $maps
     * @param array<int, array{id:string, name:string}> $review
     * @param array<string, mixed> $state
     */
    public function saveAll(array $maps, array $review, array $state): void
    {
        $dataDir = kf2_wsmc_root_path('data');
        if (!is_dir($dataDir) && !mkdir($dataDir, 0777, true) && !is_dir($dataDir)) {
            throw new RuntimeException('Failed to create data directory.');
        }

        $this->writeJson(kf2_wsmc_data_path('maps.json'), $maps);
        $this->writeJson(kf2_wsmc_data_path('review.json'), $review);
        $this->writeJson(kf2_wsmc_data_path('state.json'), $state);
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    public function loadMaps(): array
    {
        return $this->loadList(kf2_wsmc_data_path('maps.json'));
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    public function loadReview(): array
    {
        return $this->loadList(kf2_wsmc_data_path('review.json'));
    }

    /**
     * @return array<string, mixed>
     */
    public function loadState(): array
    {
        $path = kf2_wsmc_data_path('state.json');
        if (!is_file($path)) {
            return [
                'phase' => null,
                'last_run_at' => null,
                'workshop_total_items' => 0,
                'detailed_items_analyzed' => 0,
                'maps_count' => 0,
                'review_count' => 0,
                'browse_pages_processed' => 0,
                'browse_pages_limit' => null,
                'requested_max_browse_pages' => null,
                'status' => 'idle',
                'error' => null,
            ];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int, array{id:string, name:string}>
     */
    private function loadList(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param mixed $payload
     */
    private function writeJson(string $path, $payload): void
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON for ' . $path);
        }

        file_put_contents($path, $json . PHP_EOL, LOCK_EX);
    }
}
