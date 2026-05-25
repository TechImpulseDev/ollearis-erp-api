<?php
echo "Received Data";

$mac = isset($_GET["mac"]) ? trim((string)$_GET["mac"]) : "";
echo '<br>MAC: ' . $mac;

$pairs = extractDynamicPairs($_GET);
echo '<br>Total valid pairs: ' . count($pairs);

$result = insertData($mac, $pairs);
echo '<br>DB Result: ' . $result["message"];


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
		$valueKey = "V" . $index;
		if (!array_key_exists($valueKey, $normalized)) {
			continue; // Incomplete pair: ignore.
		}

		if ($idParamValue === "") {
			continue; // Empty id_param: ignore.
		}

		$pairs[] = array(
			"id_param" => $idParamValue,
			"valor" => (string)$normalized[$valueKey]
		);
	}

	return $pairs;
}


function insertData($mac, $pairs)
{
	include 'connection.php';
	$fecha_act_ins = date('Y-m-d H:i:s');
	$data = array('error' => '1', 'message' => 'No data sent!');

	try {
		$con = new PDO($connection, $username, $password);
		$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		if ($mac === "") {
			return array('error' => '1', 'message' => 'MAC no enviada.');
		}

		if (count($pairs) === 0) {
			return array('error' => '1', 'message' => 'No hay pares Pn/Vn validos.');
		}

		$sqlSensor = 'SELECT sensores.id
		              FROM sensores
		              INNER JOIN dispositivos ON sensores.id_dispositivo = dispositivos.id
		              WHERE dispositivos.mac = :mac
		              LIMIT 1';
		$stmtSensor = $con->prepare($sqlSensor);
		$stmtSensor->execute(array(':mac' => $mac));
		$sensor = $stmtSensor->fetch(PDO::FETCH_ASSOC);

		if (!$sensor || !isset($sensor["id"])) {
			return array('error' => '1', 'message' => 'Esta MAC no existe en la BBDD!');
		}

		$id_sensor = (int)$sensor["id"];
		$insertCount = 0;

		$sqlInsert = 'INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
		              VALUES (NULL, :id_sensor, :id_param, :fecha, :valor)';
		$stmtInsert = $con->prepare($sqlInsert);

		$con->beginTransaction();
		foreach ($pairs as $pair) {
			$stmtInsert->execute(
				array(
					':id_sensor' => $id_sensor,
					':id_param' => $pair["id_param"],
					':fecha' => $fecha_act_ins,
					':valor' => $pair["valor"]
				)
			);
			$insertCount++;
		}
		$con->commit();

		$data = array('error' => '0', 'message' => 'Inserted ' . $insertCount . ' rows.');
	} catch (PDOException $e) {
		if (isset($con) && $con->inTransaction()) {
			$con->rollBack();
		}
		$data = array('error' => '2', 'message' => 'Db error! ' . $e->getMessage());
	}

	return $data;
}

/*
function readData($id_sensor, $param){
	include 'connection.php';
	try
	{
		$con = new PDO($connection, $username, $password);
		$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if (isset($_GET)) {

            //GENERATED
            if ($param == 'actual'){
             $sql='SELECT * FROM lectura WHERE id_sensor = '.$id_sensor.' ORDER BY fecha DESC limit 2';
            }

            echo $sql;
            $stmt = $con->prepare($sql);
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if ($stmt->rowCount() > 0) {
                echo json_encode('error' => '0', 'data' => $data);
            } else {
                $data = array('error' => '1', 'message' => 'No se ha recibido informacion!');
                echo json_encode($data);
            }

		} else {
			$data = array('error' => '1', 'message' => 'No data sent!');
		}
	}
	catch(PDOException $e)
	{
		$data = array('error' => '2', 'message' => 'Db error!' . $e->getMessage());
	}

	echo json_encode($data);
}
*/
?>
