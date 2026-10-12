<?php
declare(strict_types=1);
namespace App\Catalog;

/** Local candidates, never provider-confirmed identities. */
final class FilenameParser
{
    public function parse(string $path, string $hint = 'mixed'): array
    {
        $portable = PHP_OS_FAMILY === 'Windows' ? str_replace('\\', '/', $path) : $path;
        $name = pathinfo($portable, PATHINFO_FILENAME);
        $parent = basename(dirname($portable));
        $result = ['type'=>'unresolved','title'=>null,'year'=>null,'season'=>null,'episodes'=>[]];
        if (preg_match('/^(.*?)[ ._-]*S(\d{1,2})((?:E\d{1,3})+)(?:-E?(\d{1,3}))?(?=$|[ ._\-])/i', $name, $m)
            || preg_match('/^(.*?)[ ._-]*(\d{1,2})x(\d{1,3})(?:-(\d{1,3}))?(?=$|[ ._\-])/i', $name, $m)) {
            $season = (int)$m[2];
            preg_match_all('/\d+/', $m[3], $numbers);
            $episodes = array_map('intval', $numbers[0]);
            if (!empty($m[4])) {
                $last = (int)$m[4];
                if ($last < end($episodes) || $last - $episodes[0] > 30) { return $result; }
                $episodes = range($episodes[0], $last);
            }
            $title = $this->clean($m[1]);
            if ($title === '') {
                $title = $this->clean(preg_match('/^(season[ ._-]*\d+|specials)$/i', $parent) ? basename(dirname(dirname($portable))) : $parent);
            }
            if ($this->generic($title)) { return $result; }
            return ['type'=>'tv','title'=>$title,'year'=>null,'season'=>$season,'episodes'=>array_values(array_unique($episodes))];
        }
        if ($hint === 'tv' || preg_match('/(?:^|[ ._-])(?:S\d+|E\d+|\d{4}[ ._-]\d{2}[ ._-]\d{2})(?:$|[ ._-])/i', $name)) { return $result; }
        $title = $this->clean($name);
        if ($this->generic($title)) {
            $title = $this->clean($parent);
            if (!preg_match('/.+[ (\[]((?:19|20)\d{2})[)\] ]*$/u', $title)) { return $result; }
        }
        $year = null;
        // A leading number (1917, 2001, etc.) is a title, not a release year.
        if (preg_match('/^(.+?)\s*[\[( ]((?:19|20)\d{2})[\]) ]*(?:$|\s)/u', $title, $m)) {
            $title = trim($m[1]); $year = (int)$m[2];
        }
        if ($this->generic($title)) { return $result; }
        return ['type'=>'movie','title'=>$title,'year'=>$year,'season'=>null,'episodes'=>[]];
    }

    private function clean(string $value): string
    {
        $value = str_replace(['.','_'], ' ', $value);
        $value = preg_replace('/(?:^|\s)(?:\d{3,4}p|2160|4k|8k|bluray|blu-ray|brrip|bdrip|web-dl|webrip|hdtv|dvdrip|remux|x26[45]|h[ .]?26[45]|hevc|av1|aac|dts|ac3|proper|repack)\b.*$/iu', '', $value);
        return trim(preg_replace('/\s+/u', ' ', $value), " \t\n\r\0\x0B-[]");
    }

    private function generic(string $title): bool
    {
        return $title === '' || (bool)preg_match('/^(?:video|movie|file|clip|sample|trailer|disc|cd|season|movies|tv|downloads|media)[\s_-]*\d*$/iu', $title);
    }
}
