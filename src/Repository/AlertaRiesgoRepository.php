<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/**
 * Handles persistence of alertas_riesgo rows.
 *
 * The table is owned by Module 1's DDL:
 *   alertas_riesgo(id, transaccion_id, score, nivel, razones JSONB, procesada_en)
 * with a 1:1 constraint on transaccion_id.
 *
 * Uses INSERT … ON CONFLICT DO UPDATE so re-processing a transaction
 * overwrites the previous score without violating the unique constraint.
 */
final class AlertaRiesgoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Upsert a risk alert for the given transaction.
     *
     * @param  int    $transaccionId
     * @param  float  $score          0.0 – 1.0
     * @param  string $nivel          bajo | medio | alto
     * @param  array  $razones        List of human-readable reasons
     * @return int    ID of the inserted/updated row
     */
    public function upsert(int $transaccionId, float $score, string $nivel, array $razones): int
    {
        $sql = <<<SQL
            INSERT INTO alertas_riesgo (transaccion_id, score, nivel, razones, procesada_en)
            VALUES (:transaccion_id, :score, :nivel, :razones::jsonb, NOW())
            ON CONFLICT (transaccion_id)
            DO UPDATE SET
                score        = EXCLUDED.score,
                nivel        = EXCLUDED.nivel,
                razones      = EXCLUDED.razones,
                procesada_en = EXCLUDED.procesada_en
            RETURNING id
        SQL;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':transaccion_id' => $transaccionId,
            ':score'          => $score,
            ':nivel'          => $nivel,
            ':razones'        => json_encode($razones, JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * Fetch an existing alert by transaction ID, or null if not found.
     */
    public function findByTransaccion(int $transaccionId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM alertas_riesgo WHERE transaccion_id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $transaccionId]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }
}
