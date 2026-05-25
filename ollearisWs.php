<?php
//echo exec("/var/www/html/sct/readValueScript.sh ".$_GET["mac"]." ".$_GET["sensor"]." ".$_GET["fecha"]." ".$_GET["parameter"]." ".$_GET["value"]);
echo "Received Data";

echo '<br>MAC: ' . ($_GET["mac"]);
echo '<br>Parameter1: ' . ($_GET["id_param1"]);
echo '<br>Parameter2: ' . ($_GET["id_param2"]);
echo '<br>Parameter3: ' . ($_GET["id_param3"]);
echo '<br>Parameter4: ' . ($_GET["id_param4"]);
echo '<br>Parameter5: ' . ($_GET["id_param5"]);

echo '<br>Value1: ' . ($_GET["temperature"]);
echo '<br>Value2: ' . ($_GET["flow"]);
echo '<br>Value3: ' . ($_GET["presion"]);
echo '<br>Value4: ' . ($_GET["elapsed"]);
echo '<br>Value5: ' . ($_GET["flow2"]);
$nuevo=0;
$mac = $_GET["mac"];
/*if ($mac=="CC:AD:BE:EF:FE:AA")
{
	$nuevo=1;
	$mac="EE:AD:BE:EF:FE:AD";
}
 */
$nuevo=1;
$parameter1 = $_GET["id_param1"];
$parameter2 = $_GET["id_param2"];
$parameter3 = $_GET["id_param3"];
$parameter4 = $_GET["id_param4"];
$parameter5 = $_GET["id_param5"];

$temperature = $_GET["temperature"];
$flow = $_GET["flow"];
$flow2 = $_GET["flow2"];

$pressure = $_GET["presion"];
echo "PRESION : ".$pressure;
$elapsed = $_GET["elapsed"];
if($nuevo==1)
{
$parameter1=0;
$parameter2=7;
$parameter3=6;
$parameter4=21;
$parameter5=0;
$temperature=0;
//$pressure=0;
echo "pressure   ".$pressure;
$flow=$_GET["cantidad"];
$elapsed=$_GET["tiempo"];
$pressure=$_GET["presion"];
//$elapsed=10;
//$flow=2;
}

insertData($mac,$parameter1,$parameter2,$parameter3,$parameter4,$parameter5,$temperature, $flow, $pressure, $elapsed,$flow2,$nuevo);


function insertData($mac,$parameter1,$parameter2,$parameter3,$parameter4,$parameter5,$temperature, $flow, $pressure, $elapsed,$flow2,$nuevo){
	include 'connection.php';
        $fecha_act_ins=date('Y-m-d H:i:s');

	try
	{
		$con = new PDO($connection, $username, $password);
		$con->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		if (isset($_GET)) {

            //GENERATED

			// Hacer select de dispositivo con mac y cruzar con sensores id_dispositivo
			
			$sql = 'SELECT sensores.id FROM sensores, dispositivos WHERE sensores.id_dispositivo = dispositivos.id AND dispositivos.mac = "'.$mac.'"';
            //echo $sql;
			$stmt = $con->prepare($sql);
            		$stmt->execute();
			$results = $stmt->fetchAll(PDO::FETCH_ASSOC);
			if ($stmt->rowCount() > 0) {
                		$id_sensor = $results[0]['id'];
						$temperature = 99; // FIJAMOS LOS VALORES PARA QUE NO INTERVENGAN (SE INTRODUCEN EN OTRA TABLA)
						
				// TEMPERATURE
				if ($nuevo==0)
				{
				$temp_ant=file_get_contents('temp.actual');
				$fecha_ant=file_get_contents('fecha.actual');
				$fecha_act=date('Y-m-d H:i:s');
			   	file_put_contents('fecha.actual',$fecha_act);
				 $difer=$temp_ant - $temperature;
                                if ( $difer < 0 ) { $difer = - $difer;}
				if($difer > 2)
					{	
					$sql='INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
				VALUES
				(NULL ,'.$id_sensor.','.$parameter1.',"'.$fecha_act_ins.'", "'.$temperature.'")';
					file_put_contents('dentro.actual',$sql);
					}
				else
					{
					$sql='UPDATE lectura set fecha="'.$fecha_act_ins.'", valor='.$temperature.' where id_sensor='.$id_sensor.' and id_param='.$parameter1.' and fecha="'.$fecha_ant.'"';
		                        file_put_contents('dentro.actual',$sql);

					}
				file_put_contents('temp.actual', $temperature);
				echo $sql;
				$stmt = $con->prepare($sql);
				$stmt->execute();
				}	
				//FLOW
				//
				if($nuevo==1)
				{
				$flows=file_get_contents('flow.actual');
				$factorf=file_get_contents('factor.actual');
				$flow=$flow*$factorf;
				$difer=$flows - $flow;
				$difer=10;
				if ( $difer < 0 ) { $difer = - $difer;}
				//if (substr($flows,0,3) <> substr ($flow,0,3) )
				if ( $difer > 5)
                                        {
                                        $sql='INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
                                VALUES
                                (NULL ,'.$id_sensor.','.$parameter2.',"'.$fecha_act_ins.'",'.$flow.')';
                                        }
                                else
                                        {
                                        $sql='UPDATE lectura set fecha="'.$fecha_act_ins.'",valor='.$flow.'  where id_sensor='.$id_sensor.' and id_param='.$parameter2.' order by fecha desc limit 1';
					file_put_contents('dentro.actual',$sql);

                                        }
				echo $sql;
                                file_put_contents('flow.actual', $flow);

				$stmt = $con->prepare($sql);
				$stmt->execute();
				}	
                                //FLOW2
                                //
				if($nuevo==3)
				{
                                $flows=file_get_contents('flow2.actual');
                                $difer=$flows - $flow2;
				$difer=6;
                                if ( $difer < 0 ) { $difer = - $difer;}
                                //if (substr($flows,0,3) <> substr ($flow,0,3) )
                                if ( $difer > 20)
                                        {
                                        $sql='INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
                                VALUES
                                (NULL ,'.$id_sensor.','.$parameter5.',"'.$fecha_act_ins.'", "'.$flow2.'")';
                                        }
                                else
                                        {
                                        $sql='UPDATE lectura set fecha="'.$fecha_act_ins.'" where id_sensor='.$id_sensor.' and id_param='.$parameter5.' and fecha="'.$fecha_ant.'"';
                                           file_put_contents('dentro.actual',$sql);

                                        }
                                echo $sql;
                                file_put_contents('flow2.actual', $flow2);

                                $stmt = $con->prepare($sql);
                                $stmt->execute();
				}

				if($pressure>0)
				{
				//PRESSURE
				//
					//
				$pressure=round($pressure,1);
                                        $sql='INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
                                VALUES
                                (NULL ,'.$id_sensor.','.$parameter3.',"'.$fecha_act_ins.'", "'.$pressure.'")';
				$stmt = $con->prepare($sql);
				$stmt->execute(); 
				}
				if($nuevo==1)
				{	
				//ELAPSED TIME
				$sql='INSERT INTO lectura (id, id_sensor, id_param, fecha, valor)
				VALUES
				(NULL ,'.$id_sensor.','.$parameter4.',"'.$fecha_act_ins.'", "'.$elapsed.'")';

				echo $sql;
				if ( $elapsed > 1 )
				{
				$stmt = $con->prepare($sql);
				$stmt->execute();
				}
				file_put_contents('tiempol.actual', $elapsed);
				}
				$data = array('error' => '0', 'message' => 'New data inserted!');
				
				

            } else {
                $data = array('error' => '1', 'message' => 'Esta MAC no existe en la BBDD!');
                //echo json_encode($data);
            }



		} else {
			$data = array('error' => '1', 'message' => 'No data sent!');
		}
	}
	catch(PDOException $e)
	{
		$data = array('error' => '2', 'message' => 'Db error!' . $e->getMessage());
	}

	//echo json_encode($data);
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
