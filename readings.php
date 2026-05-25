<?php

date_default_timezone_set('Europe/Madrid');

header('Content-Type: application/json; charset=utf-8');

try {
	$request = getRequestData();
	$mac = getStringValue($request, 'mac');
	$pairs = extractDynamicPairs($request);

	if ($mac === '') {
		sendJson(400, array(
			'error' => true,
			'message' => 'MAC no enviada.'
		));
	}

	if (count($pairs) === 0) {
		sendJson(400, array(
			'error' => true,
			'message' => 'No hay pares Pn/Vn validos.'
		));
	}

	$result = insertData($mac, $pairs);
	sendJson(201, array(
		'error' => false,
		'message' => 'Lecturas insertadas correctamente.',
		'inserted' => $result['inserted']
	));
} catch (InvalidArgumentException $e) {
	sendJson(400, array(
		'error' => true,
		'message' => $e->getMessage()
	));
} catch (PDOException $e) {
	error_log('[ollearisWs] Database error: ' . $e->getMessage());
	sendJson(500, array(
		'error' => true,
		'message' => 'Error de base de datos.'
	));
} catch (RuntimeException $e) {
	sendJson(404, array(
		'error' => true,
		'message' => $e->getMessage()
	));
} catch (Exception $e) {
	error_log('[ollearisWs] Unexpected error: ' . $e->getMessage());
	sendJson(500, array(
		'error' => true,
		'message' => 'Error interno.'
	));
}

function getRequestData()
{
	$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

	if ($method === 'POST') {
		$contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
		if (stripos($contentType, 'application/json') !== false) {
			$payload = json_decode(file_get_contents('php://input'), true);
			if (!is_array($payload)) {
				throw new InvalidArgumentException('JSON invalido.');
			}

			return $payload;
		}

		return $_POST;
	}

	if ($method === 'GET') {
		return $_GET;
	}

	header('Allow: GET, POST');
	sendJson(405, array(
		'error' => true,
		'message' => 'Metodo no permitido.'
	));
}

function getStringValue($data, $key)
{
	foreach ($data as $dataKey => $value) {
		if (strtolower((string)$dataKey) === strtolower($key)) {
			return trim((string)$value);
		}
	}

	return '';
}

function extractDynamicPairs($queryParams)
{
	$normalized = array();
	foreach ($queryParams as $key => $value) {
		$normalized[strtoupper((string)$key)] = $value;
	}

	$pairIndexes = array();
	foreach ($normalized as $key => $value) {
		if (preg_match('/^P(\d+)$/', $key, $matches)) {
			$pairIndexes[(int)$matches[1]] = trim((string)$value);
		}
	}

	ksort($pairIndexes);

	$pairs = array();
	foreach ($pairIndexes as $index => $idParamValue) {
		$valueKey = 'V' . $index;
		if (!array_key_exists($valueKey, $normalized)) {
			continue;
		}

		if ($idParamValue === '') {
			continue;
		}

		if (!ctype_digit($idParamValue)) {
			throw new InvalidArgumentException('El parametro P' . $index . ' debe ser numerico.');
		}

		$value = trim((string)$normalized[$valueKey]);
		if ($value === '') {
			continue;
		}

		$pairs[] = array(
			'id_param' => (int)$idParamValue,
			'valor' => $value
		);
	}

	return $pairs;
}

function insertData($mac, $pairs)
{
	$connectionFile = __DIR__ . '/connection.php';
	if (!is_file($connectionFile)) {
		throw new Exception('Missing database configuration file.');
	}

	require $connectionFile;

	if (!isset($connection, $username, $password)) {
		throw new Exception('Incomplete database configuration.');
	}

	$con = new PDO($connection, $username, $password);
	$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

	$sqlSensor = 'SELECT sensores.id
		FROM sensores
		INNER JOIN dispositivos ON sensores.id_dispositivo = dispositivos.id
		WHERE dispositivos.mac = :mac
		LIMIT 1';

	$stmtSensor = $con->prepare($sqlSensor);
	$stmtSensor->execute(array(':mac' => $mac));
	$sensor = $stmtSensor->fetch(PDO::FETCH_ASSOC);

	if (!$sensor || !isset($sensor['id'])) {
		throw new RuntimeException('Esta MAC no existe en la BBDD.');
	}

	$idSensor = (int)$sensor['id'];
	$fecha = date('Y-m-d H:i:s');
	$inserted = 0;

	$sqlInsert = 'INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
		VALUES (NULL, :id_sensor, :id_param, :fecha, :valor)';
	$stmtInsert = $con->prepare($sqlInsert);

	try {
		$con->beginTransaction();
		foreach ($pairs as $pair) {
			$stmtInsert->execute(array(
				':id_sensor' => $idSensor,
				':id_param' => $pair['id_param'],
				':fecha' => $fecha,
				':valor' => $pair['valor']
			));
			$inserted++;
		}
		$con->commit();
	} catch (Exception $e) {
		if ($con->inTransaction()) {
			$con->rollBack();
		}

		throw $e;
	}

	return array('inserted' => $inserted);
}

function sendJson($statusCode, $payload)
{
	http_response_code($statusCode);
	echo json_encode($payload, JSON_UNESCAPED_UNICODE);
	exit;
}
