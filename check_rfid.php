<?php
header('Content-Type: text/plain; charset=utf-8');

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';
$DB_NAME = 'pepert_corps_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($conn->connect_error) {
    echo "ERROR|DBCON";
    exit;
}

$uid = isset($_GET['uid']) ? strtoupper(trim($_GET['uid'])) : '';
if ($uid === '') {
    echo "ERROR|NOUID";
    $conn->close();
    exit;
}

// Check if the card is registered
$stmt = $conn->prepare("SELECT rfid_status FROM rfid_reg WHERE rfid_data = ? LIMIT 1");
if (!$stmt) {
    echo "ERROR|PREPARE_SELECT";
    $conn->close();
    exit;
}

$stmt->bind_param("s", $uid);
$stmt->execute();
$res = $stmt->get_result();

if ($res && $res->num_rows > 0) {
    // --- SCENARIO 1: USER IS REGISTERED ---
    $row = $res->fetch_assoc();
    $status = intval($row['rfid_status']);
    $newStatus = ($status === 0) ? 1 : 0;

    $stmt->close();

    // 1. Update the Registration Table
    $update = $conn->prepare("UPDATE rfid_reg SET rfid_status = ? WHERE rfid_data = ?");
    if (!$update) {
        echo "ERROR|PREPARE_UPDATE";
        $conn->close();
        exit;
    }
    $update->bind_param("is", $newStatus, $uid);
    $update->execute();
    $update->close();

    // 2. Log the activity (Status is 0 or 1)
    $log = $conn->prepare("INSERT INTO rfid_logs (time_log, rfid_data, rfid_status) VALUES (NOW(), ?, ?)");
    if (!$log) {
        echo "ERROR|PREPARE_LOG";
        $conn->close();
        exit;
    }
    $log->bind_param("si", $uid, $newStatus);
    $log->execute();
    $log->close();

    echo "FOUND|$newStatus";

} else {
    // --- SCENARIO 2: USER IS NOT REGISTERED ---
    if ($stmt) $stmt->close();

    // 1. Log the unauthorized attempt with NULL status
    // We use NULL directly in the SQL string
    $logUnreg = $conn->prepare("INSERT INTO rfid_logs (time_log, rfid_data, rfid_status) VALUES (NOW(), ?, NULL)");
    
    if ($logUnreg) {
        $logUnreg->bind_param("s", $uid);
        $logUnreg->execute();
        $logUnreg->close();
    } else {

    }

    echo "NOTFOUND|$uid";
}

$conn->close();
?>