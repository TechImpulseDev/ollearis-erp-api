<?php

date_default_timezone_set('Europe/Madrid');

header('Content-Type: application/json; charset=utf-8');

$requestId = createRequestId();

try {
	$request = getRequestData();
	$mac = getStringValue($request, 'mac');
	$pairs = extractDynamicPairs($request);

	if ($mac === '') {
		logMessage('warning', 'MAC no enviada.', array(
			'request_id' => $requestId,
			'method' => getRequestMethod()
		));
		sendJson(400, array(
			'error' => true,
			'message' => 'MAC no enviada.',
			'request_id' => $requestId
		));
	}

	if (count($pairs) === 0) {
		logMessage('warning', 'No hay pares validos.', array(
			'request_id' => $requestId,
			'method' => getRequestMethod(),
			'mac' => $mac
		));
		sendJson(400, array(
			'error' => true,
			'message' => 'No hay parametros validos.',
			'request_id' => $requestId
		));
	}

	$result = insertData($mac, $pairs);
	sendJson(201, array(
		'error' => false,
		'message' => 'Lecturas insertadas correctamente.',
		'inserted' => $result['inserted'],
		'request_id' => $requestId
	));
} catch (InvalidArgumentException $e) {
	logMessage('warning', $e->getMessage(), array(
		'request_id' => $requestId,
		'method' => getRequestMethod()
	));
	sendJson(400, array(
		'error' => true,
		'message' => $e->getMessage(),
		'request_id' => $requestId
	));
} catch (PDOException $e) {
	logMessage('error', 'Database error.', array(
		'request_id' => $requestId,
		'exception' => $e->getMessage()
	));
	sendJson(500, array(
		'error' => true,
		'message' => 'Error de base de datos.',
		'request_id' => $requestId
	));
} catch (RuntimeException $e) {
	logMessage('warning', $e->getMessage(), array(
		'request_id' => $requestId,
		'method' => getRequestMethod()
	));
	sendJson(404, array(
		'error' => true,
		'message' => $e->getMessage(),
		'request_id' => $requestId
	));
} catch (Exception $e) {
	logMessage('error', 'Unexpected error.', array(
		'request_id' => $requestId,
		'exception' => $e->getMessage()
	));
	sendJson(500, array(
		'error' => true,
		'message' => 'Error interno.',
		'request_id' => $requestId
	));
}

function getRequestMethod()
{
	return isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
}

function getRequestData()
{
	$method = getRequestMethod();

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

	$hasExplicitValues = false;
	foreach ($normalized as $key => $value) {
		if (preg_match('/^V\d+$/', $key)) {
			$hasExplicitValues = true;
			break;
		}
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

		if (!$hasExplicitValues) {
			if ($idParamValue === '') {
				continue;
			}

			$pairs[] = array(
				'id_param' => $index,
				'valor' => $idParamValue
			);
			continue;
		}

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

function createRequestId()
{
	if (function_exists('random_bytes')) {
		return bin2hex(random_bytes(8));
	}

	return str_replace('.', '', uniqid('', true));
}

function logMessage($level, $message, $context)
{
	$logDir = __DIR__ . '/logs';
	if (!is_dir($logDir) && !mkdir($logDir, 0775, true)) {
		error_log('[readings] Cannot create log directory: ' . $logDir);
		return;
	}

	$entry = array(
		'timestamp' => date('c'),
		'level' => strtoupper($level),
		'message' => $message,
		'context' => $context
	);

	$encoded = json_encode($entry, JSON_UNESCAPED_UNICODE);
	if ($encoded === false) {
		$encoded = json_encode(array(
			'timestamp' => date('c'),
			'level' => 'ERROR',
			'message' => 'Unable to encode log entry.'
		));
	}

	$logFile = $logDir . '/api-error.log';
	if (file_put_contents($logFile, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
		error_log('[readings] Cannot write log file: ' . $logFile);
	}
}
