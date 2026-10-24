<?php
// Resolve package descriptions with the same YAML language files used by plugins.
require $argv[2];
$result = [];
foreach (glob($argv[1].'/*/package.json') as $file) {
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    foreach (['en', 'zh_CN'] as $locale) {
        $item = $data;
        foreach (['title', 'description'] as $field) {
            $value = $item[$field] ?? ($field === 'title' ? $item['name'] : '');
            if (str_contains($value, '::')) {
                [, $key] = explode('::', $value, 2);
                $parts = explode('.', $key);
                $group = array_shift($parts);
                $value = null;
                foreach (array_unique([$locale, 'en']) as $language) {
                    $path = dirname($file).'/lang/'.$language.'/'.$group.'.yml';
                    if (!is_file($path)) continue;
                    $translation = Symfony\Component\Yaml\Yaml::parseFile($path);
                    foreach ($parts as $part) $translation = is_array($translation) ? ($translation[$part] ?? null) : null;
                    if (is_string($translation)) { $value = $translation; break; }
                }
                if ($value === null) throw new RuntimeException('Missing '.$locale.' translation: '.$file.' '.$key);
            }
            $item[$field] = $value;
        }
        $item['author'] = $item['author'] ?? '';
        $result[$data['name']][$locale] = $item;
    }
}
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
