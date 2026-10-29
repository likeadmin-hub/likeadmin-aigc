<?php
namespace app\common\service\app\aigc_short_drama;

/** Preserve only unambiguous, individually valid shots from a received part. */
final class ShortDramaPartialStoryboard
{
    public static function accepted(array $part, array $ids, callable $validate): ?array
    {
        $shots = $part['storyboard'] ?? null;
        if (!is_array($shots) || !array_is_list($shots) || count($shots) >= count($ids)) return null;
        $accepted = []; $last = -1;
        foreach ($shots as $shot) {
            if (!is_array($shot)) return null;
            $index = array_search($shot['shot_id'] ?? null, $ids, true);
            // An unknown, repeated or reordered ID makes the response ambiguous.
            if ($index === false || $index <= $last) return null;
            $last = $index;
            try { $validate($shot, $index); }
            catch (\RuntimeException $error) {
                if (!in_array($error->getCode(), [409, 413, 422], true)) throw $error;
                return null;
            }
            $accepted[$index] = $shot;
        }
        return $accepted;
    }
}
