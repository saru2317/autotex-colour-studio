<?php
declare(strict_types=1);
require __DIR__.'/paths.php';
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!is_string($action)) at_fail('Invalid request.');
if ($action === 'catalog' && $method === 'GET') {
    $catalog = at_catalog()['catalog'];
    $catalog['colours'] = array_values(array_filter($catalog['colours'], fn($c) => $c['visible']));
    if ($catalog['product']) $catalog['product'] = ['id'=>$catalog['product']['id'], 'name'=>$catalog['product']['name']];
    at_json($catalog);
}
if ($action === 'settings' && in_array($method, ['GET','PUT'], true)) {
    at_require_admin($method === 'PUT');
    session_write_close();
    if ($method === 'GET') at_json(at_catalog());
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) at_fail('JSON settings are required.', 415);
    $raw = file_get_contents('php://input', false, null, 0, 40001);
    if ($raw === false || strlen($raw) > 40000) at_fail('Settings are too large.', 413);
    try { $data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException) { at_fail('Invalid settings JSON.'); }
    if (!is_array($data)) at_fail('Invalid settings.');
    at_json(at_save_catalog($data));
}
if ($action === 'upload' && $method === 'POST') {
    at_require_admin(true); session_write_close();
    if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > AT_MAX_FILE * 2 + 100000) at_fail('Upload is too large.', 413);
    at_json(['product'=>at_upload()]);
}
if ($action === 'model' && $method === 'GET') {
    if (isset($_GET['draft'])) { at_require_admin(); $id = $_GET['draft']; if (!at_valid_id($id)) at_fail('Invalid product.'); }
    else { $product = at_catalog()['catalog']['product']; if (!$product) at_fail('No product has been uploaded.', 404); $id = $product['id']; }
    at_send_file(at_product_dir($id).'/preview.glb', 'model/gltf-binary');
}
if ($action === 'source' && $method === 'GET') {
    at_require_admin(); $product = at_catalog()['catalog']['product'];
    if (!$product) at_fail('No product has been uploaded.', 404);
    at_send_file(at_product_dir($product['id']).'/source.step', 'application/step', $product['filename']);
}
at_fail('This API operation or request method is not supported.', 405);
