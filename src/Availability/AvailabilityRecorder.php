<?php

declare(strict_types=1);

namespace App\Availability;

use Doctrine\DBAL\Connection;

final class AvailabilityRecorder
{
    public function __construct(private readonly Connection $db) {}
    public function record(string $run, array $file, string $state): void
    {
        $this->db->transactional(function () use ($run, $file, $state): void {
            $now = gmdate(DATE_ATOM);
            $known = in_array($state, ['available','missing'], true);
            $outcome = !$known ? 'uncheckable' : ($state === $file['last_known_availability'] ? 'unchanged' : ($state === 'missing' ? 'newly_missing' : 'restored'));
            $reason = $known ? null : str_replace('_', ' ', $state) . ': path could not be verified.';
            $values = ['check_outcome' => $state,'check_error' => $reason,'last_checked_at' => $now,'updated_at' => $now];
            if ($known) {
                $values['last_known_availability'] = $state;
            }
            if ($state === 'available') {
                $values['last_seen_at'] = $now;
            }
            $this->db->update('media_files', $values, ['id' => $file['id']]);
            $this->db->insert('run_file_results', ['run_id' => $run,'file_id' => $file['id'],'file_identity' => $file['normalized_path'],'outcome' => $outcome,'path_snapshot' => $file['original_path'],'current_state' => $state,'diagnostic_code' => $known ? null : $state,'details' => $reason]);
            $this->db->executeStatement('UPDATE runs SET examined=examined+1,' . $outcome . '=' . $outcome . '+1 WHERE id=?', [$run]);
            if (in_array($outcome, ['newly_missing','restored'], true) || (!$known && $state !== $file['check_outcome'])) {
                $this->db->insert('events', ['occurred_at' => $now,'event_type' => $known ? $outcome : 'check_failed','run_id' => $run,'file_id' => $file['id'],'path_snapshot' => $file['original_path'],'reason' => $reason,'before_values' => json_encode(['availability' => $file['last_known_availability']]),'after_values' => json_encode(['availability' => $state])] + \App\Catalog\FileEventContext::snapshot($this->db, (int) $file['id']));
            }
        });
    }
}
