<?php

declare(strict_types=1);

namespace App\Modules\Members\Repositories;

use App\Shared\Logging\Logger;
use PDO;

class MandateDocumentRepository
{
    public function __construct(
        private PDO $db,
        private Logger $logger,
    ) {}

    public function findByMemberId(string $memberId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM mandate_documents WHERE member_id = :member_id'
        );
        $stmt->execute(['member_id' => $memberId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Insert on first upload, update on replacement.
     */
    public function upsert(array $data): array
    {
        $existing = $this->findByMemberId($data['member_id']);

        if ($existing !== null) {
            $stmt = $this->db->prepare(
                'UPDATE mandate_documents
                 SET file_path             = :file_path,
                     original_filename     = :original_filename,
                     file_size_bytes       = :file_size_bytes,
                     uploaded_by_admin_id  = :uploaded_by_admin_id,
                     extraction_status     = NULL,
                     extracted_data        = NULL
                 WHERE member_id = :member_id'
            );
            $stmt->execute([
                'file_path'            => $data['file_path'],
                'original_filename'    => $data['original_filename'],
                'file_size_bytes'      => $data['file_size_bytes'],
                'uploaded_by_admin_id' => $data['uploaded_by_admin_id'],
                'member_id'            => $data['member_id'],
            ]);
        } else {
            $id = $this->generateUuid();
            $stmt = $this->db->prepare(
                'INSERT INTO mandate_documents
                     (id, member_id, file_path, original_filename, file_size_bytes, uploaded_by_admin_id)
                 VALUES
                     (:id, :member_id, :file_path, :original_filename, :file_size_bytes, :uploaded_by_admin_id)'
            );
            $stmt->execute([
                'id'                   => $id,
                'member_id'            => $data['member_id'],
                'file_path'            => $data['file_path'],
                'original_filename'    => $data['original_filename'],
                'file_size_bytes'      => $data['file_size_bytes'],
                'uploaded_by_admin_id' => $data['uploaded_by_admin_id'],
            ]);
        }

        return (array) $this->findByMemberId($data['member_id']);
    }

    public function deleteByMemberId(string $memberId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM mandate_documents WHERE member_id = :member_id'
        );
        $stmt->execute(['member_id' => $memberId]);
        return $stmt->rowCount() > 0;
    }

    private function generateUuid(): string
    {
        // Matches the cryptographically-secure pattern used in all other repositories
        $data    = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
