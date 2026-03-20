<?php
/** @var modX $modx */

// Получаем данные формы, которые mixedImage передаёт в сниппет автоматически
$data = $modx->getOption('data', $scriptProperties, []);

// Иногда data приходит JSON-строкой, преобразуем в массив
if (is_string($data)) {
    $data = $modx->fromJSON($data);
}

// На всякий случай проверяем, что это массив
if (!is_array($data)) {
    $data = [];
}

// ID текущего ресурса
$resId = (int)($data['id'] ?? 0);

// ID родителя ресурса
$parentId = (int)($data['parent'] ?? 0);

// Значение по умолчанию для alias родителя
$parentAlias = 'root';

// Если parent задан, пытаемся получить alias родителя
if ($parentId > 0) {
    /** @var modResource $parent */
    $parent = $modx->getObject('modResource', $parentId);

    if ($parent) {
        $parentAlias = (string)$parent->get('alias');
    }
}

// Очищаем alias родителя для безопасного использования в пути
$parentAlias = modResource::filterPathSegment($modx, $parentAlias);

// По умолчанию категория отсутствует
$category = '';

// Пробуем сначала взять значение TV 132 из данных формы.
// Часто mixedImage уже передаёт TV в formdata, и это быстрее, чем отдельный запрос.
if (isset($data['tv132'])) {
    $category = trim((string)$data['tv132']);
}

// Если в formdata TV не нашли, а ресурс уже существует — пробуем получить значение через TV объект
if ($category === '' && $resId > 0) {
    /** @var modTemplateVar $tv */
    $tv = $modx->getObject('modTemplateVar', 132);

    if ($tv) {
        $category = trim((string)$tv->getValue($resId));
    }
}

// Если значение TV содержит несколько категорий через ||
// например: cat1||cat2||cat3
// берём только первую
if ($category !== '' && strpos($category, '||') !== false) {
    $parts = explode('||', $category);
    $category = trim((string)$parts[0]);
}

// Если категория есть — очищаем её и возвращаем путь images/parent/category
if ($category !== '') {
    $category = modResource::filterPathSegment($modx, $category);

    if ($category !== '') {
        return 'images/' . $parentAlias . '/' . $category;
    }
}

// Если категории нет — возвращаем просто images/parent
return 'images/' . $parentAlias;
