<?php
/**
 * A small catalog for the staging install: a parent category, three under it, and fourteen
 * books with covers, a few of them marked down, so that product blocks in
 * an email have something to show.
 *
 *   cd <openmage-core> && php <module>/dev/staging/seed-catalog.php
 *
 * Invented titles; the covers are flat colour with the title on, drawn here.
 * Running it again replaces what it made last time.
 */
require_once 'app/Mage.php';
Mage::app('admin');
Mage::register('isSecureArea', true);

$store   = Mage::app()->getDefaultStoreView();
$rootId  = (int) $store->getRootCategoryId();
$setId   = (int) Mage::getModel('catalog/product')->getDefaultAttributeSetId();
$website = (int) $store->getWebsiteId();

foreach (Mage::getResourceModel('catalog/product_collection')->addAttributeToFilter('sku', ['like' => 'SEED-%']) as $old) {
    $old->delete();
}

foreach (Mage::getResourceModel('catalog/category_collection')->addAttributeToFilter('url_key', ['in' => ['new-release', 'new-releases', 'new-sefroim', 'new-english-children-books', 'seed-new-books', 'seed-new-seforim', 'seed-childrens']]) as $old) {
    $old->delete();
}

$categories = [];

// A parent with three children, as a bookshop's "new releases" usually is:
// the parent is an anchor, so asking it for products gives all three's.
$parent = Mage::getModel('catalog/category')->setStoreId(0);
$parent->addData([
    'name' => 'New Release', 'url_key' => 'new-release', 'is_active' => 1, 'include_in_menu' => 1, 'is_anchor' => 1,
    'display_mode' => 'PRODUCTS', 'path' => Mage::getModel('catalog/category')->load($rootId)->getPath(),
])->save();

foreach (['new-releases' => 'New Books', 'new-sefroim' => 'New Seforim', 'new-english-children-books' => "New Children's Books"] as $key => $name) {
    $category = Mage::getModel('catalog/category')->setStoreId(0);
    $category->addData([
        'name' => $name, 'url_key' => $key, 'is_active' => 1, 'include_in_menu' => 1, 'is_anchor' => 1,
        'display_mode' => 'PRODUCTS', 'path' => $parent->getPath(),
    ])->save();
    $categories[$key] = (int) $category->getId();
}

$dir = Mage::getBaseDir('media') . DS . 'import';
@mkdir($dir, 0777, true);

$books = [
    // title, category, price, special price or null
    ['What Leah Found', 'new-english-children-books', 24.99, null], ['The Starlight Sisters, Volume 5', 'new-english-children-books', 22.99, null],
    ['Cobra', 'new-releases', 34.99, null], ['Still Only Mimi', 'new-english-children-books', 19.99, null],
    ['Stories of Greatness', 'new-releases', 29.99, null], ['Good Night, Rebbe', 'new-english-children-books', 18.99, null],
    ['A Gentle Word', 'new-releases', 27.99, null], ['The Quiet Hour', 'new-releases', 31.99, 26.99],
    ['אור אביגדור - חובת הלבבות - שער הבטחון', 'new-sefroim', 38.75, 33.99],
    ['Eight People We Met On the Way Home', 'new-releases', 44.99, 33.99],
    ['די אינוואזיע אין נארמאנדי #2', 'new-sefroim', 36.99, 33.99],
    ['Daily Dose', 'new-releases', 29.99, 19.99],
    ['משנה ברורה - חלק ג', 'new-sefroim', 42.00, null], ['Letters to a Friend', 'new-releases', 21.50, null],
];
$palette = [[122, 31, 61], [31, 78, 121], [58, 90, 64], [107, 66, 0], [60, 60, 90], [140, 70, 40]];

foreach ($books as $i => [$title, $categoryKey, $price, $special]) {
    $sku  = sprintf('SEED-%03d', $i + 1);
    $file = $dir . DS . strtolower($sku) . '.png';

    // A 600x900 cover: a block of colour with a lighter band and the SKU
    $image = imagecreatetruecolor(600, 900);
    [$r, $g, $b] = $palette[$i % count($palette)];
    imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));
    imagefilledrectangle($image, 60, 300, 540, 600, imagecolorallocate($image, min(255, $r + 90), min(255, $g + 90), min(255, $b + 90)));
    imagestring($image, 5, 240, 440, 'BOOK ' . ($i + 1), imagecolorallocate($image, 255, 255, 255));
    imagepng($image, $file);
    imagedestroy($image);

    $product = Mage::getModel('catalog/product');
    $product->setStoreId(0)->addData([
        'sku' => $sku, 'name' => $title, 'type_id' => 'simple', 'attribute_set_id' => $setId,
        'website_ids' => [$website], 'category_ids' => [$categories[$categoryKey]],
        'price' => $price, 'special_price' => $special, 'status' => 1, 'visibility' => 4, 'tax_class_id' => 0, 'weight' => 1,
        'description' => $title, 'short_description' => $title,
        'url_key' => 'seed-' . ($i + 1) . '-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($title)),
        'created_at' => gmdate('Y-m-d H:i:s', time() - (count($books) - $i) * 86400),
        'stock_data' => ['use_config_manage_stock' => 1, 'qty' => 50, 'is_in_stock' => 1],
    ]);
    $product->addImageToMediaGallery($file, ['image', 'small_image', 'thumbnail'], false, false);
    $product->save();
}

echo 'categories: ' . json_encode($categories) . "\n" . count($books) . " products\n";
