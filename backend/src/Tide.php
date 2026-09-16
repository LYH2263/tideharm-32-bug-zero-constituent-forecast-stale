<?php
declare(strict_types=1);

namespace App;

use InvalidArgumentException;
use PDO;

final class Tide
{
    public static function listStations(PDO $db): array
    {
        $rows = $db->query('SELECT slug, name, datum_m FROM stations ORDER BY id')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $res = self::residuals($db, $r['slug']);
            $out[] = [
                'slug' => $r['slug'],
                'name' => $r['name'],
                'datum_m' => (float)$r['datum_m'],
                'max_abs_residual_m' => $res['max_abs_residual_m'] ?? 0.0,
                'ok' => ($res['max_abs_residual_m'] ?? 0) <= self::threshold($db),
            ];
        }
        return $out;
    }

    public static function getStation(PDO $db, string $slug): ?array
    {
        $st = $db->prepare('SELECT * FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        return [
            'slug' => $row['slug'],
            'name' => $row['name'],
            'datum_m' => (float)$row['datum_m'],
            'constituents' => self::listConstituents($db, $slug) ?? [],
        ];
    }

    public static function listConstituents(PDO $db, string $slug): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        $st = $db->prepare('SELECT name, speed_deg_per_hour, amplitude_m, phase_deg FROM constituents WHERE station_id = ? ORDER BY id');
        $st->execute([$id]);
        return array_map(static function ($r) {
            return [
                'name' => $r['name'],
                'speed_deg_per_hour' => (float)$r['speed_deg_per_hour'],
                'amplitude_m' => (float)$r['amplitude_m'],
                'phase_deg' => (float)$r['phase_deg'],
            ];
        }, $st->fetchAll());
    }

    public static function saveConstituents(PDO $db, string $slug, array $items): array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            throw new InvalidArgumentException('station not found');
        }
        if (!$items) {
            throw new InvalidArgumentException('items required');
        }
        foreach ($items as $it) {
            $amp = (float)($it['amplitude_m'] ?? -1);
            $spd = (float)($it['speed_deg_per_hour'] ?? 0);
            if ($amp < 0 || $spd <= 0 || empty($it['name'])) {
                throw new InvalidArgumentException('invalid constituent');
            }
        }
        $db->prepare('DELETE FROM constituents WHERE station_id = ?')->execute([$id]);
        $ins = $db->prepare('INSERT INTO constituents(station_id, name, speed_deg_per_hour, amplitude_m, phase_deg) VALUES (?,?,?,?,?)');
        foreach ($items as $it) {
            $ins->execute([
                $id,
                (string)$it['name'],
                (float)$it['speed_deg_per_hour'],
                (float)$it['amplitude_m'],
                (float)($it['phase_deg'] ?? 0),
            ]);
        }
        return ['items' => self::listConstituents($db, $slug)];
    }

    public static function levelAt(array $constituents, float $datum, float $tHours): float
    {
        $sum = $datum;
        foreach ($constituents as $c) {
            $rad = deg2rad($c['speed_deg_per_hour'] * $tHours + $c['phase_deg']);
            $sum += $c['amplitude_m'] * cos($rad);
        }
        return round($sum, 4);
    }

    public static function forecast(PDO $db, string $slug, int $hours, int $stepMin): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        return ['slug' => $slug, 'points' => self::forecastPoints($st['constituents'], $st['datum_m'], $hours, $stepMin)];
    }

    /**
     * 分潮贡献拆解：在内存中把指定分潮剔除后重算预报点列与残差，全程不写库。
     * 未知站返回 null（路由给 404），未知分潮抛 InvalidArgumentException（路由给 400）。
     * residuals 字段直接内嵌同条件残差接口的完整回包，两处必然一致。
     */
    public static function decompose(PDO $db, string $slug, string $without, int $hours, int $stepMin): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $kept = self::withoutConstituent($st['constituents'], $without);
        $removedAmp = 0.0;
        foreach ($st['constituents'] as $c) {
            if ($c['name'] === $without) {
                $removedAmp = $c['amplitude_m'];
            }
        }

        $trial = self::forecastPoints($kept, $st['datum_m'], $hours, $stepMin);
        $formal = self::forecastPoints($st['constituents'], $st['datum_m'], $hours, $stepMin);
        $points = [];
        foreach ($formal as $i => $p) {
            $points[] = [
                't_hours' => $p['t_hours'],
                'formal_level_m' => $p['level_m'],
                'trial_level_m' => $trial[$i]['level_m'],
                // 拆解预报相对正式预报的潮位差（拆解 − 正式）
                'diff_m' => round($trial[$i]['level_m'] - $p['level_m'], 4),
            ];
        }

        return [
            'slug' => $slug,
            'constituent' => $without,
            'removed_amplitude_m' => $removedAmp,
            'points' => $points,
            'residuals' => self::residuals($db, $slug, $without),
        ];
    }

    /**
     * 确认拆解：把该分潮振幅写成零。仅在显式确认时写库。
     * 未知站返回 null，未知分潮抛 InvalidArgumentException。
     */
    public static function zeroConstituent(PDO $db, string $slug, string $name): ?array
    {
        $id = self::stationId($db, $slug);
        if ($id === null) {
            return null;
        }
        self::withoutConstituent(self::listConstituents($db, $slug), $name);
        $items = self::listConstituents($db, $slug) ?? [];
        foreach ($items as &$c) {
            if ($c['name'] === $name) {
                $c['amplitude_m'] = 0.0;
            }
        }
        unset($c);
        return [
            'slug' => $slug,
            'constituent' => $name,
            'items' => $items,
        ];
    }

    private static function forecastPoints(array $constituents, float $datum, int $hours, int $stepMin): array
    {
        $hours = max(1, min(168, $hours));
        $stepMin = max(5, min(120, $stepMin));
        $points = [];
        for ($m = 0; $m <= $hours * 60; $m += $stepMin) {
            $t = $m / 60.0;
            $points[] = ['t_hours' => round($t, 4), 'level_m' => self::levelAt($constituents, $datum, $t)];
        }
        return $points;
    }

    public static function residuals(PDO $db, string $slug, ?string $without = null): ?array
    {
        $st = self::getStation($db, $slug);
        if ($st === null) {
            return null;
        }
        $constituents = $st['constituents'];
        if ($without !== null && $without !== '') {
            $constituents = self::withoutConstituent($constituents, $without);
        }
        $id = self::stationId($db, $slug);
        $q = $db->prepare('SELECT t_hours, level_m FROM observations WHERE station_id = ? ORDER BY t_hours');
        $q->execute([$id]);
        $thr = self::threshold($db);
        $items = [];
        $maxAbs = 0.0;
        $anyOver = false;
        foreach ($q->fetchAll() as $obs) {
            $t = (float)$obs['t_hours'];
            $formal = self::levelAt($st['constituents'], $st['datum_m'], $t);
            $pred = self::levelAt($constituents, $st['datum_m'], $t);
            $res = round((float)$obs['level_m'] - $pred, 4);
            $over = abs($res) > $thr;
            $maxAbs = max($maxAbs, abs($res));
            $anyOver = $anyOver || $over;
            $items[] = [
                't_hours' => $t,
                'observed_m' => (float)$obs['level_m'],
                'predicted_m' => $pred,
                'formal_predicted_m' => $formal,
                'residual_m' => $res,
                'over' => $over,
            ];
        }
        return [
            'slug' => $slug,
            'without' => $without,
            'threshold_m' => $thr,
            'max_abs_residual_m' => round($maxAbs, 4),
            'any_over' => $anyOver,
            'items' => $items,
        ];
    }

    public static function settings(PDO $db): array
    {
        return ['residual_threshold_m' => self::threshold($db)];
    }

    public static function saveSettings(PDO $db, array $body): array
    {
        $v = (float)($body['residual_threshold_m'] ?? 0);
        if ($v <= 0 || $v > 5) {
            throw new InvalidArgumentException('residual_threshold_m out of range');
        }
        $db->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
            ->execute(['residual_threshold_m', (string)$v]);
        return self::settings($db);
    }

    /**
     * 返回剔除指定分潮后的分潮列表（纯内存，不写库）。分潮名不存在即拒绝。
     */
    private static function withoutConstituent(array $constituents, string $name): array
    {
        $found = false;
        $kept = [];
        foreach ($constituents as $c) {
            if ($c['name'] === $name) {
                $found = true;
                continue;
            }
            $kept[] = $c;
        }
        if (!$found) {
            throw new InvalidArgumentException('constituent not found: ' . $name);
        }
        return $kept;
    }

    private static function threshold(PDO $db): float
    {
        $st = $db->query("SELECT value FROM settings WHERE key = 'residual_threshold_m'")->fetch();
        return $st ? (float)$st['value'] : 0.15;
    }

    private static function stationId(PDO $db, string $slug): ?int
    {
        $st = $db->prepare('SELECT id FROM stations WHERE slug = ?');
        $st->execute([$slug]);
        $row = $st->fetch();
        return $row ? (int)$row['id'] : null;
    }
}
