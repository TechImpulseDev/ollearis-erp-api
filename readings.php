<?php

date_default_timezone_set('Europe/Madrid');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if (getRequestMethod() === 'OPTIONS') {
	http_response_code(204);
	exit;
}

$requestId = createRequestId();

try {
	$request = getRequestData();
	if (getRequestMethod() === 'GET') {
		logIncomingGetRequest($requestId, $request);
	}

	$mac = getStringValue($request, 'mac');
	$pairs = extractDynamicPairs($request);
	$alarmParamIds = extractAlarmParamIds($request);
	validateAlarmParamIds($alarmParamIds, $pairs);

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

	$result = insertData($mac, $pairs, $alarmParamIds);
	sendJson(201, array(
		'error' => false,
		'message' => 'Lecturas insertadas correctamente.',
		'inserted' => $result['inserted'],
		'alarm_inserted' => $result['alarm_inserted'],
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

function extractAlarmParamIds($queryParams)
{
	$alarmValues = array();
	foreach ($queryParams as $key => $value) {
		if (strtolower((string)$key) === 'alarm') {
			if (is_array($value)) {
				foreach ($value as $item) {
					$alarmValues[] = (string)$item;
				}
			} else {
				$alarmValues[] = (string)$value;
			}
		}
	}

	if (getRequestMethod() === 'GET' && isset($_SERVER['QUERY_STRING'])) {
		$parts = explode('&', $_SERVER['QUERY_STRING']);
		foreach ($parts as $part) {
			if ($part === '') {
				continue;
			}

			$keyValue = explode('=', $part, 2);
			$key = urldecode($keyValue[0]);
			if (strtolower($key) !== 'alarm' && strtolower($key) !== 'alarm[]') {
				continue;
			}

			$alarmValues[] = isset($keyValue[1]) ? urldecode($keyValue[1]) : '';
		}
	}

	if (count($alarmValues) === 0) {
		return array();
	}

	$alarmParamIds = array();
	foreach ($alarmValues as $alarmValue) {
		$tokens = preg_split('/[\s,;|]+/', trim((string)$alarmValue));
		foreach ($tokens as $token) {
			if ($token === '') {
				continue;
			}

			if (!preg_match('/^p?(\d+)$/i', $token, $matches)) {
				throw new InvalidArgumentException('El parametro alarm debe tener formato pXX.');
			}

			$alarmParamIds[(int)$matches[1]] = true;
		}
	}

	return array_keys($alarmParamIds);
}

function validateAlarmParamIds($alarmParamIds, $pairs)
{
	if (count($alarmParamIds) === 0) {
		return;
	}

	$receivedParamIds = array();
	foreach ($pairs as $pair) {
		$receivedParamIds[(int)$pair['id_param']] = true;
	}

	foreach ($alarmParamIds as $alarmParamId) {
		if (!isset($receivedParamIds[(int)$alarmParamId])) {
			throw new InvalidArgumentException('El parametro alarm p' . $alarmParamId . ' no existe entre las lecturas recibidas.');
		}
	}
}

function insertData($mac, $pairs, $alarmParamIds)
{
	$connectionFile = dirname(__DIR__) . '/connection.php';
	if (!is_file($connectionFile)) {
		throw new Exception('Missing database configuration file: ' . $connectionFile);
	}

	require $connectionFile;

	if (!isset($connection, $username, $password)) {
		throw new Exception('Incomplete database configuration in ' . $connectionFile . '.');
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
	$alarmInserted = 0;
	$alarmParamLookup = array_fill_keys($alarmParamIds, true);

	$sqlInsert = 'INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
		VALUES (NULL, :id_sensor, :id_param, :fecha, :valor)';
	$stmtInsert = $con->prepare($sqlInsert);
	$sqlInsertAlarm = 'INSERT INTO lectura_alarma (id, id_sensor, id_param, fecha, valor)
		VALUES (NULL, :id_sensor, :id_param, :fecha, :valor)';
	$stmtInsertAlarm = $con->prepare($sqlInsertAlarm);

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

			if (isset($alarmParamLookup[(int)$pair['id_param']])) {
				$stmtInsertAlarm->execute(array(
					':id_sensor' => $idSensor,
					':id_param' => $pair['id_param'],
					':fecha' => $fecha,
					':valor' => $pair['valor']
				));
				$alarmInserted++;
			}
		}
		$con->commit();
	} catch (Exception $e) {
		if ($con->inTransaction()) {
			$con->rollBack();
		}

		throw $e;
	}

	return array(
		'inserted' => $inserted,
		'alarm_inserted' => $alarmInserted
	);
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

function logIncomingGetRequest($requestId, $queryParams)
{
	logMessage('info', 'Incoming GET request.', array(
		'request_id' => $requestId,
		'method' => getRequestMethod(),
		'url' => getFullRequestUrl(),
		'request_uri' => isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '',
		'query_string' => isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '',
		'query_params' => $queryParams,
		'client_ip' => getClientIp(),
		'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : ''
	));
}

function getFullRequestUrl()
{
	$scheme = 'http';
	if (
		(isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower($_SERVER['HTTPS']) !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
	) {
		$scheme = 'https';
	}

	$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
	$requestUri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';

	if ($host === '') {
		return $requestUri;
	}

	return $scheme . '://' . $host . $requestUri;
}

function getClientIp()
{
	if (isset($_SERVER['HTTP_X_FORWARDED_FOR']) && trim($_SERVER['HTTP_X_FORWARDED_FOR']) !== '') {
		$parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
		return trim($parts[0]);
	}

	if (isset($_SERVER['REMOTE_ADDR'])) {
		return $_SERVER['REMOTE_ADDR'];
	}

	return '';
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
