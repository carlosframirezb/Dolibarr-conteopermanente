<?php
/**
 * \file       htdocs/custom/conteopermanente/conteo_permanente.php
 * \ingroup    conteopermanente
 * \brief      Vista de conteo con escáner, sesión, historial dinámico agrupado por sesión con exportación CSV (;) y PDF.
 */

if (!defined('NOCSRFCHECK'))     define('NOCSRFCHECK', 1);
if (!defined('NOTOKENRENEWAL'))  define('NOTOKENRENEWAL', 1);

$res = @include '../../main.inc.php';
if (!$res) $res = @include '../../../main.inc.php';

if (empty($user->rights->conteopermanente->leer) && empty($user->rights->produit->lire)) {
    accessforbidden('No tienes permisos suficientes para acceder al Conteo Permanente.');
}

// Determinar si el usuario es administrador del módulo
$isAdmin = (!empty($user->admin) || !empty($user->rights->conteopermanente->creer) || !empty($user->rights->conteopermanente->supprimer));
$mode = GETPOST('mode', 'alpha'); // 'admin' para panel de control, vacío para escáner

// -------------------------------------------------------------------------
// FUNCIÓN AUXILIAR: SANITIZAR VALORES MONETARIOS / DECIMALES
// -------------------------------------------------------------------------
function clean_decimal_input($input) {
    if (empty($input)) return null;
    
    $clean = trim(str_replace(array('$', ' ', "\xc2\xa0"), '', $input));
    if ($clean === '') return null;

    if (strpos($clean, ',') !== false && strpos($clean, '.') !== false) {
        if (strrpos($clean, ',') > strrpos($clean, '.')) {
            $clean = str_replace('.', '', $clean);
            $clean = str_replace(',', '.', $clean);
        } else {
            $clean = str_replace(',', '', $clean);
        }
    } elseif (strpos($clean, ',') !== false) {
        $clean = str_replace(',', '.', $clean);
    }

    return (is_numeric($clean)) ? (float)$clean : 0.0;
}

// -------------------------------------------------------------------------
// 1. AUTO-CREACIÓN DE TABLA EN BD SI NO EXISTE
// -------------------------------------------------------------------------
$tableName = MAIN_DB_PREFIX . "conteo_permanente_log";
$sqlCheck = "SHOW TABLES LIKE '" . $tableName . "'";
$resCheck = $db->query($sqlCheck);

if ($resCheck && $db->num_rows($resCheck) == 0) {
    $sqlCreate = "CREATE TABLE " . $tableName . " (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        session_id VARCHAR(64) NOT NULL,
        barcode VARCHAR(128) NOT NULL,
        description_actual TEXT,
        description_nueva TEXT,
        entrepot VARCHAR(255) NOT NULL,
        qty_actual INT NOT NULL,
        qty_nueva INT NOT NULL,
        price_actual DOUBLE(24,8) DEFAULT 0,
        price_nuevo DOUBLE(24,8) DEFAULT NULL,
        fk_user INT NOT NULL,
        date_creation DATETIME NOT NULL,
        INDEX idx_session_id (session_id)
    ) ENGINE=innodb DEFAULT CHARSET=utf8mb4;";
    $db->query($sqlCreate);
}

// -------------------------------------------------------------------------
// 2. EXPORTACIÓN A CSV (DELIMITADOR PUNTO Y COMA ';') Y PDF CON AGRUPACIÓN Y CANTIDAD
// -------------------------------------------------------------------------
if (GETPOST('action', 'aZ09') === 'export_csv') {
    date_default_timezone_set('America/Caracas');

    $session_id  = trim(GETPOST('session_id', 'alpha'));
    $search_user = (int) GETPOST('search_user', 'int');
    $search_code = trim(GETPOST('search_code', 'alpha'));

    $sql = "SELECT c.barcode, ";
    $sql .= "COUNT(c.rowid) as cantidad_escaneos, ";
    $sql .= "MAX(c.date_creation) as ultima_fecha, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.description_actual ORDER BY c.rowid DESC), ',', 1) as description_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(c.description_nueva, '') ORDER BY c.rowid DESC), ',', 1) as description_nueva, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.entrepot ORDER BY c.rowid DESC), ',', 1) as entrepot, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.qty_actual ORDER BY c.rowid DESC), ',', 1) as qty_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.qty_nueva ORDER BY c.rowid DESC), ',', 1) as qty_nueva, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.price_actual ORDER BY c.rowid DESC), ',', 1) as price_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(c.price_nuevo, '') ORDER BY c.rowid DESC), ',', 1) as price_nuevo ";
    $sql .= "FROM " . MAIN_DB_PREFIX . "conteo_permanente_log c ";
    $sql .= "WHERE 1=1 ";

    if (!empty($session_id)) {
        $sql .= "AND c.session_id = '" . $db->escape($session_id) . "' ";
    }
    if ($search_user > 0) {
        $sql .= "AND c.fk_user = " . $search_user . " ";
    }
    if (!empty($search_code)) {
        $sql .= "AND (c.barcode LIKE '%" . $db->escape($search_code) . "%' OR c.description_actual LIKE '%" . $db->escape($search_code) . "%') ";
    }

    $sql .= "GROUP BY c.barcode ";
    $sql .= "ORDER BY ultima_fecha DESC";

    $resql = $db->query($sql);

    $filename = !empty($session_id) ? "conteo_" . $session_id . ".csv" : "reporte_conteos_" . date('Ymd') . ".csv";

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    echo "\xEF\xBB\xBF";

    echo "Fecha;Codigo;Cantidad;Referencia_Actual;Referencia_Nueva;Almacen;Cant_Actual;Cant_Nueva;Precio_Actual;Precio_Nuevo\n";

    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $onlyDate = date('Y-m-d', strtotime($obj->ultima_fecha));

            $priceNuevoFmt = ($obj->price_nuevo !== '' && $obj->price_nuevo !== null) ? number_format((float)$obj->price_nuevo, 2, ',', '') : '';

            $line = array(
                $onlyDate,
                $obj->barcode,
                $obj->cantidad_escaneos,
                str_replace(array("\r", "\n", ";"), ' ', $obj->description_actual),
                str_replace(array("\r", "\n", ";"), ' ', $obj->description_nueva),
                str_replace(array("\r", "\n", ";"), ' ', $obj->entrepot),
                $obj->qty_actual,
                $obj->qty_nueva,
                number_format((float)$obj->price_actual, 2, ',', ''),
                $priceNuevoFmt
            );
            echo implode(';', $line) . "\n";
        }
        $db->free($resql);
    }
    exit;
}

if (GETPOST('action', 'aZ09') === 'export_pdf') {
    $session_id = trim(GETPOST('session_id', 'alpha'));
    
    $sql = "SELECT c.barcode, ";
    $sql .= "COUNT(c.rowid) as cantidad_escaneos, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.description_actual ORDER BY c.rowid DESC), ',', 1) as description_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(c.description_nueva, '') ORDER BY c.rowid DESC), ',', 1) as description_nueva, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.entrepot ORDER BY c.rowid DESC), ',', 1) as entrepot, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.qty_actual ORDER BY c.rowid DESC), ',', 1) as qty_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.qty_nueva ORDER BY c.rowid DESC), ',', 1) as qty_nueva, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(c.price_actual ORDER BY c.rowid DESC), ',', 1) as price_actual, ";
    $sql .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(c.price_nuevo, '') ORDER BY c.rowid DESC), ',', 1) as price_nuevo ";
    $sql .= "FROM " . MAIN_DB_PREFIX . "conteo_permanente_log c ";
    $sql .= "WHERE c.session_id = '" . $db->escape($session_id) . "' ";
    $sql .= "GROUP BY c.barcode ORDER BY MIN(c.rowid) ASC";
    
    $resql = $db->query($sql);
    
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Reporte de Conteo - ' . dol_escape_htmltag($session_id) . '</title>';
    echo '<style>body{font-family:sans-serif;font-size:12px;} table{width:100%;border-collapse:collapse;margin-top:15px;} th,td{border:1px solid #ccc;padding:6px;text-align:left;} th{background:#eee;} @media print { button { display:none; } }</style>';
    echo '</head><body onload="window.print()">';
    echo '<h2>Reporte de Sesión de Conteo</h2>';
    echo '<p><b>Sesión:</b> ' . dol_escape_htmltag($session_id) . '</p>';
    echo '<button onclick="window.print()">🖨 Imprimir / Guardar en PDF</button>';
    echo '<table><thead><tr><th>Código</th><th style="text-align:center;">Cant.</th><th>Producto</th><th>Almacén</th><th>Cant. Act</th><th>Cant. Nva</th><th>P. Act</th><th>P. Nvo</th></tr></thead><tbody>';
    
    if ($resql) {
        while ($d = $db->fetch_object($resql)) {
            $priceNuevoFmt = ($d->price_nuevo !== '' && $d->price_nuevo !== null) ? number_format((float)$d->price_nuevo, 2, ',', '.') : '-';
            echo '<tr>';
            echo '<td>' . dol_escape_htmltag($d->barcode) . '</td>';
            echo '<td style="text-align:center;font-weight:bold;">' . $d->cantidad_escaneos . '</td>';
            echo '<td><span style="color:#16a34a;">' . dol_escape_htmltag($d->description_actual) . '</span>' . ($d->description_nueva ? ' <span style="color:#dc2626;">(' . dol_escape_htmltag($d->description_nueva) . ')</span>' : '') . '</td>';
            echo '<td>' . dol_escape_htmltag($d->entrepot) . '</td>';
            echo '<td>' . $d->qty_actual . '</td>';
            echo '<td>' . $d->qty_nueva . '</td>';
            echo '<td>' . number_format((float)$d->price_actual, 2, ',', '.') . '</td>';
            echo '<td>' . $priceNuevoFmt . '</td>';
            echo '</tr>';
        }
    }
    echo '</tbody></table></body></html>';
    exit;
}

// -------------------------------------------------------------------------
// 3. ENDPOINT AJAX: GUARDAR REGISTRO DE CONTEO
// -------------------------------------------------------------------------
if (GETPOST('action', 'aZ09') === 'save_conteo') {
    top_httphead('application/json');

    $session_id         = trim(GETPOST('session_id', 'alpha'));
    $barcode            = trim(GETPOST('barcode', 'alpha'));
    $description_actual = trim(GETPOST('description_actual', 'restricthtml'));
    $description_nueva  = mb_strtoupper(trim(GETPOST('description_nueva', 'restricthtml')), 'UTF-8');
    $items_raw          = GETPOST('items', 'array');

    if (empty($session_id)) {
        echo json_encode(array('success' => false, 'message' => 'Identificador de sesión inválido.'));
        exit;
    }

    if (empty($items_raw) || !is_array($items_raw)) {
        echo json_encode(array('success' => false, 'message' => 'No hay almacenes para guardar.'));
        exit;
    }

    $savedCount = 0;
    $savedItems = array();
    $db->begin();

    foreach ($items_raw as $item) {
        $entrepot         = trim($item['entrepot']);
        $qty_actual       = (int) $item['qty_actual'];
        $qty_nueva_raw    = trim($item['qty_nueva']);
        $price_actual_raw = trim($item['price_actual']);
        $price_nuevo_raw  = trim($item['price_nuevo']);

        if ($qty_nueva_raw === '') {
            continue;
        }

        $qty_nueva    = (int) $qty_nueva_raw;
        $price_actual = clean_decimal_input($price_actual_raw);
        $price_nuevo  = clean_decimal_input($price_nuevo_raw);

        $sql  = "INSERT INTO " . MAIN_DB_PREFIX . "conteo_permanente_log ";
        $sql .= "(session_id, barcode, description_actual, description_nueva, entrepot, qty_actual, qty_nueva, price_actual, price_nuevo, fk_user, date_creation) ";
        $sql .= "VALUES (";
        $sql .= "'" . $db->escape($session_id) . "', ";
        $sql .= "'" . $db->escape($barcode) . "', ";
        $sql .= "'" . $db->escape($description_actual) . "', ";
        $sql .= "'" . $db->escape($description_nueva) . "', ";
        $sql .= "'" . $db->escape($entrepot) . "', ";
        $sql .= $qty_actual . ", ";
        $sql .= $qty_nueva . ", ";
        $sql .= ($price_actual !== null ? $price_actual : 0) . ", ";
        $sql .= ($price_nuevo !== null ? $price_nuevo : "NULL") . ", ";
        $sql .= ((int) $user->id) . ", ";
        $sql .= "'" . $db->idate(dol_now()) . "'";
        $sql .= ")";

        if ($db->query($sql)) {
            $savedCount++;
            $savedItems[] = array(
                'barcode'           => $barcode,
                'qty_actual'        => $qty_actual,
                'qty_nueva'         => $qty_nueva,
                'description_nueva' => $description_nueva,
                'price_nuevo'       => $price_nuevo
            );
        } else {
            $db->rollback();
            echo json_encode(array('success' => false, 'message' => 'Error al guardar en BD: ' . $db->lasterror()));
            exit;
        }
    }

    if ($savedCount > 0) {
        $db->commit();
        echo json_encode(array(
            'success' => true, 
            'message' => 'Guardado exitosamente (' . $savedCount . ' almacén/es actualizado/s).',
            'items'   => $savedItems
        ));
    } else {
        $db->rollback();
        echo json_encode(array('success' => false, 'message' => 'Debe ingresar al menos una cantidad nueva para guardar.'));
    }
    exit;
}

// -------------------------------------------------------------------------
// 4. ENDPOINT AJAX: BÚSQUEDA DE PRODUCTO
// -------------------------------------------------------------------------
if (GETPOST('action', 'aZ09') === 'fetch_product') {
    top_httphead('application/json');

    $barcode = trim(GETPOST('barcode', 'alpha'));
    $response = array('success' => false);

    if (!empty($barcode)) {
        require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
        require_once DOL_DOCUMENT_ROOT . '/product/stock/class/entrepot.class.php';

        $product = new Product($db);
        $productId = 0;
        $clean = $db->escape($barcode);

        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "product ";
        $sql .= "WHERE barcode = '" . $clean . "' OR ref = '" . $clean . "' ";
        $sql .= "OR barcode LIKE '%" . $clean . "%' OR ref LIKE '%" . $clean . "%' LIMIT 1";

        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) {
            $obj = $db->fetch_object($resql);
            $productId = $obj->rowid;
            $db->free($resql);
        }

        if ($productId > 0 && $product->fetch($productId) > 0) {
            $product->load_stock();

            $stocks = array();
            if (!empty($product->stock_warehouse)) {
                foreach ($product->stock_warehouse as $idWarehouse => $objStock) {
                    if ($objStock->real != 0) {
                        $warehouse = new Entrepot($db);
                        $warehouse->fetch($idWarehouse);
                        $stocks[] = array(
                            'entrepot' => $warehouse->label,
                            'ref'      => $warehouse->ref ? $warehouse->ref : $warehouse->label,
                            'stock'    => $objStock->real
                        );
                    }
                }
            }

            if (empty($stocks)) {
                $defaultWarehouseLabel = '';
                $defaultWarehouseRef   = '';
                $idDefaultWh = !empty($product->fk_default_warehouse) ? $product->fk_default_warehouse : 0;
                
                if ($idDefaultWh > 0) {
                    $wh = new Entrepot($db);
                    if ($wh->fetch($idDefaultWh) > 0) {
                        $defaultWarehouseLabel = $wh->label;
                        $defaultWarehouseRef   = $wh->ref ? $wh->ref : $wh->label;
                    }
                }

                if (empty($defaultWarehouseLabel)) {
                    $sqlWh = "SELECT rowid, ref, label FROM " . MAIN_DB_PREFIX . "entrepot WHERE statut = 1 LIMIT 1";
                    $resWh = $db->query($sqlWh);
                    if ($resWh && $db->num_rows($resWh) > 0) {
                        $objWh = $db->fetch_object($resWh);
                        $defaultWarehouseLabel = $objWh->label;
                        $defaultWarehouseRef   = $objWh->ref ? $objWh->ref : $objWh->label;
                        $db->free($resWh);
                    }
                }

                $stocks[] = array(
                    'entrepot' => !empty($defaultWarehouseLabel) ? $defaultWarehouseLabel : 'Almacén Principal',
                    'ref'      => !empty($defaultWarehouseRef) ? $defaultWarehouseRef : 'MAIN',
                    'stock'    => 0
                );
            }

            $response = array(
                'success' => true,
                'data' => array(
                    'ref'     => $product->ref,
                    'label'   => $product->label,
                    'barcode' => $product->barcode ? $product->barcode : 'SIN CÓDIGO',
                    'price'   => price($product->price_ttc, 0, $langs, 1, -1, -1, $conf->currency),
                    'stocks'  => $stocks
                )
            );
        } else {
            $response['message'] = "Producto no encontrado para el código: " . $barcode;
        }
    } else {
        $response['message'] = "El código ingresado está vacío.";
    }

    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Conteo Permanente - Dolibarr</title>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        :root {
            --bg-color: #f1f5f9;
            --card-bg: #ffffff;
            --primary: #2563eb;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --danger: #ef4444;
            --success: #16a34a;
            --border-red: #dc2626;
            --btn-yellow: #f59e0b;
        }

        html.font-small { font-size: 100%; }
        html.font-medium { font-size: 118%; }
        html.font-large { font-size: 135%; }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        
        body { 
            background-color: var(--bg-color); 
            color: var(--text-main); 
            padding: 8px; 
            display: flex; 
            flex-direction: column; 
            min-height: 100vh; 
            font-size: 1rem;
        }

        .header { 
            display: flex; 
            justify-content: space-between; 
            align-items: center; 
            padding-bottom: 6px; 
            border-bottom: 2px solid var(--border-color); 
            margin-bottom: 6px; 
        } 
        
        .header h1 { font-size: 1.1rem; font-weight: 700; color: var(--text-main); }
        .header-actions { display: flex; gap: 6px; }
        
        .accessibility-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #e2e8f0;
            padding: 4px 8px;
            border-radius: 6px;
            margin-bottom: 8px;
        }

        .accessibility-controls {
            display: flex;
            align-items: center;
            gap: 6px;
            margin-left: auto;
        }

        .accessibility-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
        }

        .accessibility-btn-group {
            display: flex;
            gap: 4px;
        }

        .btn-access {
            background: #ffffff;
            border: 1px solid var(--border-color);
            color: var(--text-main);
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .btn-access.active {
            background: var(--primary);
            color: #ffffff;
            border-color: var(--primary);
        }

        .toggle-wrapper {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-main);
            cursor: pointer;
            user-select: none;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 32px;
            height: 18px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .slider {
            position: absolute;
            cursor: pointer;
            top: 0; left: 0; right: 0; bottom: 0;
            background-color: #cbd5e1;
            transition: .3s;
            border-radius: 18px;
        }

        .slider:before {
            position: absolute;
            content: "";
            height: 14px;
            width: 14px;
            left: 2px;
            bottom: 2px;
            background-color: white;
            transition: .3s;
            border-radius: 50%;
        }

        input:checked + .slider {
            background-color: var(--primary);
        }

        input:checked + .slider:before {
            transform: translateX(14px);
        }

        .btn-nav {
            background: #475569;
            color: white;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            text-decoration: none;
            font-weight: 600;
        }

        .close-btn { 
            background: var(--danger); 
            color: #ffffff; 
            border: none; 
            padding: 5px 10px; 
            border-radius: 6px; 
            font-weight: 600; 
            cursor: pointer; 
            text-decoration: none; 
            font-size: 0.8rem; 
        }

        .action-bar { display: flex; gap: 8px; margin-bottom: 8px; }
        .search-form { flex: 1; display: flex; gap: 8px; margin: 0; }
        
        .search-form input { 
            width: 100%; 
            padding: 10px; 
            border: 2px solid var(--border-color); 
            border-radius: 8px; 
            font-size: 0.9rem; 
            outline: none; 
        }
        
        .btn-camera {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0 12px;
            border-radius: 8px;
            font-weight: bold;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            white-space: nowrap;
            font-size: 0.85rem;
        }

        #scanner-wrapper {
            display: none;
            background: #000;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 8px;
            width: 100%;
        }

        #reader { width: 100%; }

        #reader video { 
            width: 100% !important;
            height: auto !important;
            object-fit: cover; 
        }

        .card { 
            background: var(--card-bg); 
            border-radius: 8px; 
            padding: 12px; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.05); 
            display: none; 
            margin-bottom: 12px;
        }
        
        .card.active { display: block; }

        .product-title { 
            font-size: 1rem; 
            font-weight: 800; 
            color: #000; 
            margin-bottom: 2px; 
            line-height: 1.2;
            text-transform: uppercase;
        }

        .product-barcode { 
            font-size: 0.8rem; 
            color: #94a3b8; 
            margin-bottom: 12px; 
        }

        .stock-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .stock-table th {
            padding: 4px 2px;
            font-size: 0.8rem;
            font-weight: 800;
            color: #000000;
            border-bottom: 1px solid var(--border-color);
        }

        .stock-table th.th-almacen { text-align: left; }
        .stock-table th.th-actual-nueva { text-align: center; }
        .stock-table th.th-precio { text-align: left; padding-left: 4px; }

        .txt-actual { color: #16a34a; }
        .txt-nueva { color: var(--border-red); }

        .stock-table td {
            padding: 4px 2px;
            font-size: 0.8rem;
            vertical-align: middle;
        }

        .wh-code { color: #64748b; font-size: 0.8rem; font-weight: 600; }
        .col-qty-center { text-align: center; white-space: nowrap; }
        .col-price-left { text-align: left; white-space: nowrap; padding-left: 4px; }

        .val-actual { font-weight: 800; color: #16a34a; font-size: 0.85rem; margin-right: 4px; }
        .val-precio { font-weight: 800; color: #16a34a; font-size: 0.85rem; margin-right: 4px; }

        .input-red {
            border: 1px solid var(--border-red);
            color: var(--border-red);
            font-weight: 700;
            border-radius: 3px;
            outline: none;
            padding: 2px 4px;
            text-align: center;
            display: inline-block;
            font-size: 0.85rem;
        }

        .input-qty { width: 2.8em; height: 1.8em; }
        .input-price { width: 4.2em; height: 1.8em; text-align: right; }

        .desc-section { margin-top: 6px; margin-bottom: 12px; }
        .desc-title { font-weight: 800; font-size: 0.85rem; color: #000; margin-bottom: 4px; }

        .textarea-red {
            width: 100%;
            border: 1px solid var(--border-red);
            color: var(--border-red);
            font-weight: 600;
            border-radius: 4px;
            outline: none;
            padding: 6px;
            min-height: 45px;
            resize: vertical;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .btn-save-yellow {
            background-color: var(--btn-yellow);
            color: #000000;
            border: none;
            padding: 8px 20px;
            border-radius: 6px;
            font-weight: 800;
            font-size: 0.9rem;
            cursor: pointer;
            float: right;
        }

        .clearfix::after { content: ""; clear: both; display: table; }

        .history-card {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            margin-top: 8px;
        }

        .history-title {
            font-size: 0.9rem;
            font-weight: 800;
            margin-bottom: 8px;
            color: var(--text-main);
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .history-table { width: 100%; border-collapse: collapse; }
        .history-table th { text-align: left; padding: 4px; font-size: 0.75rem; color: var(--text-muted); border-bottom: 1px solid var(--border-color); background: #f8fafc; }
        .history-table td { padding: 6px 4px; font-size: 0.8rem; border-bottom: 1px solid var(--border-color); vertical-align: middle; }

        .btn-icon {
            border: none;
            padding: 6px 8px;
            border-radius: 4px;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.9rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-left: 2px;
        }

        .btn-icon-blue { background-color: #3b82f6; color: white; }
        .btn-icon-green { background-color: #059669; color: white; }
        .btn-icon-red { background-color: #ef4444; color: white; }

        .filter-form {
            display: flex;
            gap: 6px;
            margin-bottom: 10px;
        }

        .filter-form input, .filter-form select {
            padding: 6px 8px;
            border: 1px solid var(--border-color);
            border-radius: 4px;
            font-size: 0.8rem;
        }

        .empty-state { text-align: center; color: var(--text-muted); margin-top: 20px; font-size: 0.85rem; }
        
        .badge-count {
            background: #e2e8f0;
            color: #1e293b;
            padding: 2px 6px;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.75rem;
            display: inline-block;
        }

        .date-cell {
            display: flex;
            flex-direction: column;
            line-height: 1.1;
        }
        .date-part { font-weight: 700; color: #0f172a; font-size: 0.78rem; }
        .time-part { font-size: 0.72rem; color: #64748b; }

        .detail-row { display: none; background-color: #f8fafc; }
        .detail-container { padding: 4px; border: 1px solid var(--border-color); border-radius: 4px; background: #ffffff; }
        
        .item-card {
            border-bottom: 1px solid #e2e8f0;
            padding: 6px 2px;
            font-size: 0.78rem;
        }
        .item-card:last-child { border-bottom: none; }
        
        .item-line-1 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2px;
            font-weight: 600;
        }

        .item-line-2 {
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #475569;
            font-size: 0.75rem;
        }

        .txt-desc-actual { color: #16a34a; font-weight: bold; }
        .txt-desc-nueva { color: #dc2626; font-weight: bold; }
        .txt-precio-actual { color: #16a34a; font-weight: bold; }
        .txt-precio-nuevo { color: #dc2626; font-weight: bold; }

        .col-cantidad { display: none; }
        .continuo-active .col-cantidad { display: table-cell; }
    </style>
</head>
<body>

    <div class="header">
        <h1>Conteo Permanente</h1>
        <div class="header-actions">
            <?php if ($isAdmin): ?>
                <?php if ($mode === 'admin'): ?>
                    <a href="conteo_permanente.php" class="btn-nav">📷 Escáner</a>
                <?php else: ?>
                    <a href="conteo_permanente.php?mode=admin" class="btn-nav">📋 Histórico</a>
                <?php endif; ?>
            <?php endif; ?>
            <button type="button" onclick="closeSessionAndExit()" class="close-btn">Cerrar</button>
        </div>
    </div>

    <div class="accessibility-bar">
        <label class="toggle-wrapper">
            <span>Continuo</span>
            <div class="switch">
                <input type="checkbox" id="chkContinuo" onchange="toggleContinuoMode(this.checked)">
                <span class="slider"></span>
            </div>
        </label>

        <div class="accessibility-controls">
            <span class="accessibility-title">Tamaño</span>
            <div class="accessibility-btn-group">
                <button type="button" class="btn-access" id="btnFontSmall" onclick="setFontSize('small')">A-</button>
                <button type="button" class="btn-access" id="btnFontMedium" onclick="setFontSize('medium')">A</button>
                <button type="button" class="btn-access" id="btnFontLarge" onclick="setFontSize('large')">A+</button>
            </div>
        </div>
    </div>

<?php if ($mode === 'admin' && $isAdmin): ?>
    <div class="history-card">
        <div class="history-title">
            <span>Histórico de Sesiones</span>
        </div>
        
        <form method="GET" action="conteo_permanente.php" class="filter-form">
            <input type="hidden" name="mode" value="admin">
            <input type="text" name="search_code" style="flex:1;" placeholder="Buscar producto o sesión..." value="<?php echo dol_escape_htmltag(GETPOST('search_code')); ?>">
            <button type="submit" style="padding: 6px 10px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size:0.8rem;">Filtrar</button>
        </form>

        <table class="history-table">
            <thead>
                <tr>
                    <th style="width: 35%;">Fecha / Hora</th>
                    <th style="text-align: center; width: 25%;">Total</th>
                    <th style="text-align: right; width: 40%;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $search_user = (int) GETPOST('search_user', 'int');
                $search_code = trim(GETPOST('search_code', 'alpha'));

                $sql = "SELECT c.session_id, MIN(c.date_creation) as fecha_inicio, COUNT(c.rowid) as total_items ";
                $sql .= "FROM " . MAIN_DB_PREFIX . "conteo_permanente_log c ";
                $sql .= "WHERE 1=1 ";

                if (!empty($search_code)) {
                    $sql .= "AND (c.session_id LIKE '%" . $db->escape($search_code) . "%' OR c.barcode LIKE '%" . $db->escape($search_code) . "%' OR c.description_actual LIKE '%" . $db->escape($search_code) . "%') ";
                }

                $sql .= "GROUP BY c.session_id ";
                $sql .= "ORDER BY fecha_inicio DESC LIMIT 100";

                $resql = $db->query($sql);
                if ($resql && $db->num_rows($resql) > 0) {
                    $idx = 0;
                    while ($obj = $db->fetch_object($resql)) {
                        $idx++;
                        $timestamp = strtotime($obj->fecha_inicio);
                        $fechaFmt = date('d/m/Y', $timestamp);
                        $horaFmt  = date('H:i:s', $timestamp);

                        echo '<tr>';
                        echo '<td><div class="date-cell"><span class="date-part">' . $fechaFmt . '</span><span class="time-part">' . $horaFmt . '</span></div></td>';
                        echo '<td style="text-align: center;"><span class="badge-count">' . $obj->total_items . '</span></td>';
                        echo '<td style="text-align: right;">';
                        echo '<button type="button" class="btn-icon btn-icon-blue" title="Ver Detalle" onclick="toggleDetail(\'detail_' . $idx . '\')">👁</button>';
                        echo '<a href="conteo_permanente.php?action=export_csv&session_id=' . urlencode($obj->session_id) . '" class="btn-icon btn-icon-green" title="Exportar CSV (;)">📥</a>';
                        echo '<a href="conteo_permanente.php?action=export_pdf&session_id=' . urlencode($obj->session_id) . '" target="_blank" class="btn-icon btn-icon-red" title="Exportar PDF">📄</a>';
                        echo '</td>';
                        echo '</tr>';

                        echo '<tr id="detail_' . $idx . '" class="detail-row"><td colspan="3">';
                        echo '<div class="detail-container">';
                        
                        $sqlDet = "SELECT barcode, ";
                        $sqlDet .= "COUNT(rowid) as cant_escaneos, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(description_actual ORDER BY rowid DESC), ',', 1) as description_actual, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(description_nueva, '') ORDER BY rowid DESC), ',', 1) as description_nueva, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(entrepot ORDER BY rowid DESC), ',', 1) as entrepot, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(qty_actual ORDER BY rowid DESC), ',', 1) as qty_actual, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(qty_nueva ORDER BY rowid DESC), ',', 1) as qty_nueva, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(price_actual ORDER BY rowid DESC), ',', 1) as price_actual, ";
                        $sqlDet .= "SUBSTRING_INDEX(GROUP_CONCAT(COALESCE(price_nuevo, '') ORDER BY rowid DESC), ',', 1) as price_nuevo ";
                        $sqlDet .= "FROM " . MAIN_DB_PREFIX . "conteo_permanente_log ";
                        $sqlDet .= "WHERE session_id = '" . $db->escape($obj->session_id) . "' GROUP BY barcode ORDER BY MIN(rowid) ASC";
                        $resDet = $db->query($sqlDet);

                        if ($resDet && $db->num_rows($resDet) > 0) {
                            while ($d = $db->fetch_object($resDet)) {
                                echo '<div class="item-card">';
                                echo '  <div class="item-line-1">';
                                echo '    <span><b>' . dol_escape_htmltag($d->barcode) . '</b> (Cant: ' . $d->cant_escaneos . ') - <span class="txt-desc-actual">' . dol_escape_htmltag($d->description_actual) . '</span>' . ($d->description_nueva ? ' <span class="txt-desc-nueva">(' . dol_escape_htmltag($d->description_nueva) . ')</span>' : '') . '</span>';
                                echo '  </div>';
                                echo '  <div class="item-line-2">';
                                echo '    <span>' . dol_escape_htmltag($d->entrepot) . '</span>';
                                echo '    <span><b>Cant:</b> <span style="color:#16a34a;font-weight:bold;">' . $d->qty_actual . '</span> ➔ <span style="color:#dc2626;font-weight:bold;">' . $d->qty_nueva . '</span> | <b>P:</b> <span class="txt-precio-actual">$' . number_format((float)$d->price_actual, 2, ',', '.') . '</span>' . ($d->price_nuevo !== '' && $d->price_nuevo !== null ? ' ➔ <span class="txt-precio-nuevo">$' . number_format((float)$d->price_nuevo, 2, ',', '.') . '</span>' : '') . '</span>';
                                echo '  </div>';
                                echo '</div>';
                            }
                            $db->free($resDet);
                        }
                        echo '</div>';
                        echo '</td></tr>';
                    }
                } else {
                    echo '<tr><td colspan="3" style="text-align: center; color: var(--text-muted); padding: 15px;">No hay registros de sesiones.</td></tr>';
                }
                ?>
            </tbody>
        </table>
    </div>

<?php else: ?>
    <!-- VISTA PRINCIPAL DE OPERACIÓN (ESCÁNER) -->
    <div class="action-bar">
        <form id="searchForm" class="search-form" action="javascript:void(0);" onsubmit="handleSearch(event)">
            <input type="text" id="barcodeInput" inputmode="numeric" pattern="[0-9-]*" placeholder="Escanear o ingresar código..." autofocus autocomplete="off" oninput="this.value = this.value.replace(/[^0-9-]/g, '')">
        </form>
        <button type="button" id="toggleCameraBtn" class="btn-camera">📷 Cámara</button>
    </div>

    <div id="scanner-wrapper">
        <div id="reader"></div>
    </div>

    <div id="emptyState" class="empty-state">
        Escanea o busca un producto para realizar el conteo.
    </div>

    <div id="productCard" class="card">
        <div class="product-title" id="pLabel">-</div>
        <div class="product-barcode" id="pBarcode">-</div>

        <table class="stock-table">
            <thead>
                <tr>
                    <th style="width: 28%;" class="th-almacen">Almacén</th>
                    <th style="width: 36%;" class="th-actual-nueva"><span class="txt-actual">Actual</span> - <span class="txt-nueva">Nueva</span></th>
                    <th style="width: 36%;" class="th-precio">Precio</th>
                </tr>
            </thead>
            <tbody id="stockRowsContainer"></tbody>
        </table>

        <div class="desc-section">
            <div class="desc-title">Descripción</div>
            <textarea id="descNuevaInput" class="textarea-red" placeholder="" oninput="this.value = this.value.toUpperCase()"></textarea>
        </div>

        <div class="clearfix">
            <button type="button" class="btn-save-yellow" onclick="saveConteoGlobal()">Guardar</button>
        </div>
    </div>

    <div class="history-card" id="historyCardContainer">
        <div class="history-title">
            <span>Revisados en esta Sesión</span>
            <div>
                <button type="button" class="btn-icon btn-icon-green" title="Exportar CSV (;)" onclick="exportData('export_csv')">📥</button>
                <button type="button" class="btn-icon btn-icon-red" title="Exportar PDF" onclick="exportData('export_pdf')">📄</button>
            </div>
        </div>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Código</th>
                    <th class="col-cantidad" style="text-align: center;">Cantidad</th>
                    <th style="text-align: center;">Actual</th>
                    <th style="text-align: center;">Nueva</th>
                </tr>
            </thead>
            <tbody id="historyTableBody">
                <tr id="noHistoryRow">
                    <td colspan="4" style="text-align: center; color: var(--text-muted); font-style: italic;">No se han guardado registros aún.</td>
                </tr>
            </tbody>
        </table>
    </div>
<?php endif; ?>

    <script>
        let isContinuoMode = localStorage.getItem('conteo_modo_continuo') === 'true';

        function setFontSize(size) {
            const root = document.documentElement;
            root.classList.remove('font-small', 'font-medium', 'font-large');
            document.querySelectorAll('.btn-access').forEach(b => b.classList.remove('active'));

            if (size === 'medium') {
                root.classList.add('font-medium');
                const btn = document.getElementById('btnFontMedium');
                if (btn) btn.classList.add('active');
            } else if (size === 'large') {
                root.classList.add('font-large');
                const btn = document.getElementById('btnFontLarge');
                if (btn) btn.classList.add('active');
            } else {
                root.classList.add('font-small');
                const btn = document.getElementById('btnFontSmall');
                if (btn) btn.classList.add('active');
            }

            localStorage.setItem('conteo_font_size', size);
        }

        function toggleContinuoMode(checked) {
            isContinuoMode = checked;
            localStorage.setItem('conteo_modo_continuo', checked);
            updateContinuoUI();
        }

        function updateContinuoUI() {
            const historyContainer = document.getElementById('historyCardContainer');
            if (historyContainer) {
                if (isContinuoMode) {
                    historyContainer.classList.add('continuo-active');
                } else {
                    historyContainer.classList.remove('continuo-active');
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            const savedFontSize = localStorage.getItem('conteo_font_size') || 'small';
            setFontSize(savedFontSize);

            const chkContinuo = document.getElementById('chkContinuo');
            if (chkContinuo) {
                chkContinuo.checked = isContinuoMode;
            }
            updateContinuoUI();
        });

        let sessionId = sessionStorage.getItem('conteo_session_id');
        if (!sessionId) {
            sessionId = 'cnt_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);
            sessionStorage.setItem('conteo_session_id', sessionId);
        }

        function closeSessionAndExit() {
            sessionStorage.removeItem('conteo_session_id');
            window.close();
        }

        function toggleDetail(id) {
            const el = document.getElementById(id);
            if (el) {
                el.style.display = (el.style.display === 'tr-row' || el.style.display === 'table-row') ? 'none' : 'table-row';
            }
        }

        const input = document.getElementById('barcodeInput');
        const card = document.getElementById('productCard');
        const emptyState = document.getElementById('emptyState');
        const toggleCameraBtn = document.getElementById('toggleCameraBtn');
        const scannerWrapper = document.getElementById('scanner-wrapper');

        let currentProductData = null;
        let html5QrCode = null;
        let isCameraActive = false;
        let isProcessing = false;

        if (input) {
            document.addEventListener('click', (e) => {
                if (e.target.tagName !== 'INPUT' && e.target.tagName !== 'BUTTON' && e.target.tagName !== 'A' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'SELECT') {
                    input.focus();
                }
            });
        }

        function handleSearch(e) {
            if (e) e.preventDefault();
            const code = input.value.trim();
            if (code) {
                fetchProduct(code);
            }
            input.value = '';
            input.blur();
            return false;
        }

        if (toggleCameraBtn) {
            toggleCameraBtn.addEventListener('click', () => {
                if (isCameraActive) {
                    stopScanner();
                } else {
                    startScanner();
                }
            });
        }

        function startScanner() {
            scannerWrapper.style.display = 'block';
            html5QrCode = new Html5Qrcode("reader");

            const config = { 
                fps: 15, 
                qrbox: (viewfinderWidth, viewfinderHeight) => {
                    return {
                        width: Math.floor(viewfinderWidth * 0.85),
                        height: Math.floor(viewfinderHeight * 0.5)
                    };
                },
                aspectRatio: 1.333333
            };

            html5QrCode.start({ facingMode: "environment" }, config, onScanSuccess)
                .then(() => {
                    isCameraActive = true;
                    toggleCameraBtn.textContent = '❌ Detener';
                    toggleCameraBtn.style.background = 'var(--danger)';
                }).catch(err => {
                    alert("No se pudo acceder a la cámara.");
                    stopScanner();
                });
        }

        function stopScanner() {
            if (html5QrCode && isCameraActive) {
                html5QrCode.stop().then(() => {
                    html5QrCode.clear();
                    scannerWrapper.style.display = 'none';
                    isCameraActive = false;
                    toggleCameraBtn.textContent = '📷 Cámara';
                    toggleCameraBtn.style.background = 'var(--primary)';
                }).catch(err => console.error(err));
            } else {
                if (scannerWrapper) scannerWrapper.style.display = 'none';
                isCameraActive = false;
                if (toggleCameraBtn) {
                    toggleCameraBtn.textContent = '📷 Cámara';
                    toggleCameraBtn.style.background = 'var(--primary)';
                }
            }
        }

        function onScanSuccess(decodedText) {
            if (isProcessing) return;
            isProcessing = true;
            
            const cleanCode = decodedText.trim();
            if (cleanCode) {
                stopScanner();
                fetchProduct(cleanCode);
            }
            
            setTimeout(() => { isProcessing = false; }, 1500);
        }

        function fetchProduct(code) {
            fetch(`conteo_permanente.php?action=fetch_product&barcode=${encodeURIComponent(code)}`)
                .then(res => res.json())
                .then(res => {
                    if (res.success) {
                        currentProductData = res.data;
                        
                        document.getElementById('pLabel').textContent = currentProductData.ref;
                        document.getElementById('pBarcode').textContent = currentProductData.barcode;
                        document.getElementById('descNuevaInput').value = '';

                        const container = document.getElementById('stockRowsContainer');
                        container.innerHTML = '';

                        currentProductData.stocks.forEach((item, index) => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>
                                    <span class="wh-code">${item.ref}</span>
                                </td>
                                <td class="col-qty-center">
                                    <span class="val-actual">${item.stock}</span>
                                    <input type="text" inputmode="numeric" pattern="[0-9]*" class="input-red input-qty" id="qty_nueva_${index}" oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                </td>
                                <td class="col-price-left">
                                    <span class="val-precio">${currentProductData.price}</span>
                                    <input type="text" inputmode="decimal" class="input-red input-price" id="price_nuevo_${index}" placeholder="" oninput="this.value = this.value.replace(/[^0-9.,]/g, '')" onblur="formatDecimal(this)">
                                </td>
                            `;
                            container.appendChild(tr);
                        });

                        emptyState.style.display = 'none';
                        card.classList.add('active');
                    } else {
                        alert(res.message || 'Producto no encontrado');
                    }
                })
                .catch(err => {
                    alert('Error en la conexión con el servidor.');
                });
        }

        function formatDecimal(input) {
            let val = input.value.trim();
            if (val !== '') {
                val = val.replace(',', '.');
                let num = parseFloat(val);
                if (!isNaN(num)) {
                    input.value = num.toFixed(2);
                }
            }
        }

        function saveConteoGlobal() {
            if (!currentProductData || !currentProductData.stocks) return;

            const descNuevaVal = document.getElementById('descNuevaInput').value.trim();
            const formData = new FormData();

            formData.append('session_id', sessionId);
            formData.append('barcode', currentProductData.barcode);
            formData.append('description_actual', currentProductData.ref);
            formData.append('description_nueva', descNuevaVal);

            let hasValidInput = false;

            currentProductData.stocks.forEach((item, index) => {
                let qtyVal = document.getElementById(`qty_nueva_${index}`).value.trim();
                const priceVal = document.getElementById(`price_nuevo_${index}`).value.trim();

                if (qtyVal === '' && isContinuoMode) {
                    qtyVal = '0';
                }

                if (qtyVal !== '') {
                    hasValidInput = true;
                }

                formData.append(`items[${index}][entrepot]`, item.entrepot);
                formData.append(`items[${index}][qty_actual]`, item.stock);
                formData.append(`items[${index}][qty_nueva]`, qtyVal);
                formData.append(`items[${index}][price_actual]`, currentProductData.price);
                formData.append(`items[${index}][price_nuevo]`, priceVal);
            });

            if (!hasValidInput) {
                alert("Debe ingresar la cantidad nueva en al menos un almacén.");
                return;
            }

            fetch('conteo_permanente.php?action=save_conteo', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(res => {
                alert(res.message);
                if (res.success) {
                    if (res.items && res.items.length > 0) {
                        addToHistory(res.items);
                    }
                    card.classList.remove('active');
                    emptyState.style.display = 'block';
                    input.focus();
                }
            })
            .catch(err => {
                alert("Error al comunicarse con el servidor.");
            });
        }

        function addToHistory(items) {
            const historyBody = document.getElementById('historyTableBody');
            const noHistoryRow = document.getElementById('noHistoryRow');

            if (noHistoryRow) {
                noHistoryRow.remove();
            }

            if (isContinuoMode) {
                items.forEach(item => {
                    let row = historyBody.querySelector(`tr[data-barcode="${item.barcode}"]`);
                    if (row) {
                        let countElem = row.querySelector('.count-val');
                        let currentCount = parseInt(countElem.textContent) || 0;
                        countElem.textContent = currentCount + 1;

                        row.querySelector('.qty-act-val').textContent = item.qty_actual;
                        row.querySelector('.qty-nva-val').textContent = item.qty_nueva;
                        
                        historyBody.insertBefore(row, historyBody.firstChild);
                    } else {
                        const tr = document.createElement('tr');
                        tr.setAttribute('data-barcode', item.barcode);
                        tr.innerHTML = `
                            <td style="font-weight: 700;">${item.barcode}</td>
                            <td class="col-cantidad" style="text-align: center; font-weight: 800; color: var(--primary);"><span class="count-val">1</span></td>
                            <td style="text-align: center; color: #16a34a; font-weight: 700;" class="qty-act-val">${item.qty_actual}</td>
                            <td style="text-align: center; color: #dc2626; font-weight: 700;" class="qty-nva-val">${item.qty_nueva}</td>
                        `;
                        historyBody.insertBefore(tr, historyBody.firstChild);
                    }
                });
            } else {
                items.forEach(item => {
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td style="font-weight: 700;">${item.barcode}</td>
                        <td class="col-cantidad" style="text-align: center; font-weight: 800; color: var(--primary);"><span class="count-val">1</span></td>
                        <td style="text-align: center; color: #16a34a; font-weight: 700;">${item.qty_actual}</td>
                        <td style="text-align: center; color: #dc2626; font-weight: 700;">${item.qty_nueva}</td>
                    `;
                    historyBody.insertBefore(tr, historyBody.firstChild);
                });
            }
        }

        function exportData(action) {
            window.open(`conteo_permanente.php?action=${action}&session_id=${encodeURIComponent(sessionId)}`, '_blank');
        }
    </script>
</body>
</html>