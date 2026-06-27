<?php

declare(strict_types=1);

namespace Genaker\Bundle\ComiVoyager\Core\Solver;

use Genaker\Bundle\ComiVoyager\Core\Model\DistanceMatrix;
use Genaker\Bundle\ComiVoyager\Core\Model\SolveOptions;

/**
 * Local search: tries relocating short segments (1-3 consecutive stops) to a
 * different position in the tour, keeping the move whenever it shortens the
 * total distance. Complements 2-opt, which cannot perform relocations.
 *
 * 2-opt ({@see TwoOptOptimizer}) can only *reverse* segments — it can never
 * pick up a single stop (or short run of stops) and drop it somewhere else
 * in the tour. Or-opt fills that gap: e.g. a stop that nearest-neighbor
 * visited "on the way past" but that really belongs between two other
 * stops can be relocated there directly. Full write-up:
 * {@see ../../doc/ALGORITHMS.md}.
 */
final class OrOptOptimizer
{
    /**
     * Segments of 1, 2, or 3 consecutive stops are tried as relocation
     * units. Longer segments are not considered — diminishing returns vs.
     * the O(n) extra positions to try for each additional length, and
     * 2-opt already handles larger-scale reordering.
     */
    private const MAX_SEGMENT_LENGTH = 3;

    /**
     * Minimum improvement (in km) required to accept a relocation — same
     * rationale as {@see TwoOptOptimizer::IMPROVEMENT_EPSILON}: prevents
     * the outer loop from looping forever on floating-point noise.
     */
    private const IMPROVEMENT_EPSILON = 1e-9;

    /**
     * Repeatedly scans every segment of length 1..{@see self::MAX_SEGMENT_LENGTH}
     * and every possible relocation target ({@see self::tryRelocate()}),
     * applying the first improving move found, until a full pass over all
     * segment lengths and positions makes no improvement at all.
     *
     * @param int[] $tour
     * @return int[]
     */
    public function optimize(DistanceMatrix $matrix, array $tour, SolveOptions $options): array
    {
        $improved = true;

        while ($improved) {
            $improved = false;
            $size = count($tour);
            // A segment can't be the entire tour minus the fixed position
            // 0 — leave at least 2 stops outside the segment (so there's
            // somewhere meaningful to relocate it to).
            $maxSegmentLength = min(self::MAX_SEGMENT_LENGTH, $size - 2);

            for ($segmentLength = 1; $segmentLength <= $maxSegmentLength; $segmentLength++) {
                // $start begins at 1 (never 0): position 0 — the depot, or
                // the free-start anchor — is never part of a relocated
                // segment.
                for ($start = 1; $start <= $size - $segmentLength; $start++) {
                    $relocated = $this->tryRelocate($matrix, $tour, $start, $segmentLength, $options);

                    if ($relocated !== null) {
                        $tour = $relocated;
                        $improved = true;
                    }
                }
            }
        }

        return $tour;
    }

    /**
     * Tries removing the segment `tour[start..start+length-1]` and
     * reinserting it immediately after every other position in the tour,
     * in its current (forward) orientation. Returns the first resulting
     * tour that is strictly shorter than the current one, or `null` if no
     * relocation of this segment helps.
     *
     * Like {@see TwoOptOptimizer::reversalDelta()}, the cost change of each
     * candidate is computed in O(1) via {@see self::relocationDelta()} —
     * only the (at most) four edges touching the segment's old and new
     * positions change, so there's no need to recompute the whole tour's
     * distance for every candidate.
     *
     * @param int[] $tour
     * @return int[]|null
     */
    private function tryRelocate(DistanceMatrix $matrix, array $tour, int $start, int $length, SolveOptions $options): ?array
    {
        $size = count($tour);

        for ($insertAfter = 0; $insertAfter < $size; $insertAfter++) {
            if ($insertAfter >= $start - 1 && $insertAfter < $start + $length) {
                // Inserting back into (or adjacent to) its own slot: no-op.
                continue;
            }

            $delta = $this->relocationDelta($matrix, $tour, $start, $length, $insertAfter, $options->returnToStart);

            if ($delta < -self::IMPROVEMENT_EPSILON) {
                return $this->relocate($tour, $start, $length, $insertAfter);
            }
        }

        return null;
    }

    /**
     * Computes how the total tour distance would change (negative = shorter
     * = improvement) if the segment `tour[start..start+length-1]` were
     * removed and reinserted immediately after `tour[insertAfter]`.
     *
     * Relocating a segment only changes the edges at its **old** boundary
     * (the legs connecting it to the stops that used to surround it) and
     * its **new** boundary (the legs connecting it to the stops it's
     * inserted between) — every edge inside the segment, and every edge
     * elsewhere in the tour, is unchanged:
     *
     *   old: ... -> before -> [segFirst ... segLast] -> after -> ...
     *               ...      -> a -> b -> ...
     *   new: ... -> before -> after -> ...
     *               ...      -> a -> [segFirst ... segLast] -> b -> ...
     *
     * delta = (new boundary edges) - (old boundary edges)
     *
     * `after` and/or `b` may not exist (the segment, or the insertion
     * point, can sit at the very end of an open path) — those terms are
     * simply omitted, since there's no edge there to remove or add. For a
     * closed loop (`$returnToStart`), a missing `after`/`b` instead wraps
     * around to `tour[0]`.
     *
     * @param int[] $tour
     */
    private function relocationDelta(DistanceMatrix $matrix, array $tour, int $start, int $length, int $insertAfter, bool $returnToStart): float
    {
        $before = $tour[$start - 1];
        $segFirst = $tour[$start];
        $segLast = $tour[$start + $length - 1];
        $after = $this->stopAfter($tour, $start + $length - 1, $returnToStart);

        $a = $tour[$insertAfter];
        $b = $this->stopAfter($tour, $insertAfter, $returnToStart);

        $removed = $matrix->distanceBetween($before, $segFirst);
        $added = $matrix->distanceBetween($a, $segFirst);

        if ($after !== null) {
            $removed += $matrix->distanceBetween($segLast, $after);
            $added += $matrix->distanceBetween($before, $after);
        }

        if ($b !== null) {
            $removed += $matrix->distanceBetween($a, $b);
            $added += $matrix->distanceBetween($segLast, $b);
        }

        return $added - $removed;
    }

    /**
     * Returns the stop immediately following `tour[position]`, or `null` if
     * `tour[position]` is the last stop of an open path (no following
     * stop). For a closed loop (`$returnToStart`), the stop after the last
     * one wraps around to `tour[0]`.
     *
     * @param int[] $tour
     */
    private function stopAfter(array $tour, int $position, bool $returnToStart): ?int
    {
        $size = count($tour);
        $nextPosition = $position + 1;

        if ($nextPosition < $size) {
            return $tour[$nextPosition];
        }

        return $returnToStart ? $tour[0] : null;
    }

    /**
     * Returns a copy of `$tour` with the segment `tour[start..start+length-1]`
     * removed and reinserted (in the same forward order) immediately after
     * the stop that was at `tour[insertAfter]`.
     *
     * @param int[] $tour
     * @return int[]
     */
    private function relocate(array $tour, int $start, int $length, int $insertAfter): array
    {
        // Pull the segment out, leaving the remaining stops in their
        // relative order.
        $segment = array_slice($tour, $start, $length);
        $remaining = $tour;
        array_splice($remaining, $start, $length);

        // $insertAfter refers to a position in the *original* tour; once
        // the segment has been removed, indices at or after $start shift
        // left by $length, so the target position in $remaining must be
        // adjusted accordingly.
        $targetPosition = $insertAfter < $start ? $insertAfter + 1 : $insertAfter + 1 - $length;
        $candidate = $remaining;
        array_splice($candidate, $targetPosition, 0, $segment);

        return $candidate;
    }
}
