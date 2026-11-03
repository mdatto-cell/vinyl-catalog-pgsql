<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/tracks.php';

function discogs_request(string $endpoint, array $query = []): array
{
    $url = 'https://api.discogs.com' . $endpoint;

    if ($query) {
        $url .= '?' . http_build_query($query);
    }

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => [
            'Authorization: Discogs token=' . DISCOGS_TOKEN,
            'User-Agent: ' . DISCOGS_USER_AGENT,
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($response === false) {
        throw new RuntimeException('Discogs cURL error: ' . curl_error($ch));
    }

    curl_close($ch);

    $data = json_decode($response, true);

    if ($httpCode >= 400) {
        throw new RuntimeException('Discogs API error HTTP ' . $httpCode . ': ' . $response);
    }

    return is_array($data) ? $data : [];
}

function discogs_search_release(?string $artist, ?string $album, ?string $catalogNumber): array
{
    $query = [
        'type' => 'release',
        'format' => 'Vinyl',
        'per_page' => 20,
    ];

    if (trim((string) $catalogNumber) !== '') {
        $query['catno'] = trim((string) $catalogNumber);
    } else {
        if (trim((string) $artist) !== '') {
            $query['artist'] = trim((string) $artist);
        }

        if (trim((string) $album) !== '') {
            $query['release_title'] = trim((string) $album);
        }
    }

    $data = discogs_request('/database/search', $query);

    return $data['results'] ?? [];
}

function discogs_get_release(int $releaseId): array
{
    return discogs_request('/releases/' . $releaseId);
}

function discogs_get_master(int $masterId): array
{
    return discogs_request('/masters/' . $masterId);
}

function discogs_tracklist_to_tracks(array $tracklist): array
{
    $tracks = [];
    $walk = static function (array $items) use (&$walk, &$tracks): void {
        foreach ($items as $index => $track) {
            $position = trim((string)($track['position'] ?? ''));
            if (!empty($track['sub_tracks']) && is_array($track['sub_tracks'])) {
                $walk($track['sub_tracks']);
                continue;
            }
            if (($track['type_'] ?? 'track') === 'heading') continue;
            $title = trim((string)($track['title'] ?? ''));
            if ($title === '') continue;
            $tracks[] = ['position' => $position, 'track_title' => $title,
                'duration' => trim((string)($track['duration'] ?? '')), 'sort_order' => count($tracks)+1];
        }
    };
    $walk($tracklist);
    return $tracks;
}

function discogs_release_to_form_data(array $release): array
{
    $tracks = discogs_tracklist_to_tracks($release['tracklist'] ?? []);
    $tracklistText = tracklist_to_text($tracks);

    $albumYear = '';
    if (!empty($release['master_id'])) {
        try {
            $master = discogs_get_master((int) $release['master_id']);
            $albumYear = $master['year'] ?? '';
        } catch (Throwable $e) {
            $albumYear = $release['year'] ?? '';
        }
    } else {
        $albumYear = $release['year'] ?? '';
    }

    return [
        'artist' => $release['artists_sort'] ?? '',
        'album_title' => $release['title'] ?? '',
        'album_year' => $albumYear,
        'release_year' => $release['year'] ?? '',
        'catalog_number' => $release['labels'][0]['catno'] ?? '',
        'barcode' => '',
        'pressing_plant' => '',
        'label_name' => $release['labels'][0]['name'] ?? '',
        'genre_name' => !empty($release['genres']) ? implode(', ', $release['genres']) : '',
        'style_name' => !empty($release['styles']) ? implode(', ', $release['styles']) : '',
        'acquired_at' => date('Y-m-d\TH:i'),
        'purchase_price' => '',
        'sale_price' => '',
        'storage_status' => 'in_stock',
        'notes' => '',
        'discogs_release_id' => $release['id'] ?? '',
        'media_condition' => '',
        'sleeve_condition' => '',
        'discogs_image_url' => $release['images'][0]['uri'] ?? '',
        'tracklist_text' => $tracklistText,
        'tracks' => $tracks,
    ];
}
