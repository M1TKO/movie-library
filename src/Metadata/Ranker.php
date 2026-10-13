<?php

declare(strict_types=1);

namespace App\Metadata;

final class Ranker
{
    public function rank(string $title, ?int $year, string $type, array $results): array
    {
        $ranked = [];
        foreach (array_slice($results, 0, 20) as $row) {
            if (!isset($row['id']) || (int) $row['id'] < 1) {
                continue;
            }
            $name = $row[$type === 'movie' ? 'title' : 'name'] ?? '';
            $original = $row[$type === 'movie' ? 'original_title' : 'original_name'] ?? '';
            $normalized = $this->normalize($title);
            $similarity = 0.0;
            foreach ([$name,$original] as $candidate) {
                similar_text($normalized, $this->normalize($candidate), $percent);
                $similarity = max($similarity, $percent / 100);
            }
            $date = $row[$type === 'movie' ? 'release_date' : 'first_air_date'] ?? '';
            $candidateYear = preg_match('/^\\d{4}-/', $date) ? (int) substr($date, 0, 4) : null;
            $yearScore = $year && $candidateYear ? ($year === $candidateYear ? 1.0 : (abs($year - $candidateYear) === 1 ? 0.4 : 0.0)) : 0.0;
            $score = round(0.8 * $similarity + 0.2 * $yearScore, 4);
            $ranked[] = ['id' => (int) $row['id'],'title' => $name ?: $original,'year' => $candidateYear,'poster_path' => $row['poster_path'] ?? null,'score' => $score,
                'evidence' => ['title_similarity' => $similarity,'requested_year' => $year,'candidate_year' => $candidateYear,'year_score' => $yearScore,'content_type' => $type],
                'strong' => $similarity === 1.0 && $yearScore === 1.0];
        }
        usort($ranked, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
        return $ranked;
    }
    public function automatic(array $ranked): bool
    {
        return isset($ranked[0]) && $ranked[0]['strong'] && $ranked[0]['score'] >= 0.95 && (!isset($ranked[1]) || $ranked[0]['score'] - $ranked[1]['score'] >= 0.10);
    }
    private function normalize(string $text): string
    {
        return trim(preg_replace('/[^\\pL\\pN]+/u', ' ', mb_strtolower($text,'UTF-8')));
    }
}
