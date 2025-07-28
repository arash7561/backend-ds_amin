<?php

require_once '../db_connection.php';

$json = file_get_contents('php://input');
$data = json_decode($json);

$fullname = $data->fullname;
$username = $data->username;
$password = $data->password;
$email = $data->email;
$mobile = $data->mobile;

$error;

if(empty($fullname)) {
    $error = 'نام و نام خانوادگی خالی است';
}
elseif(empty($username)) {
    $error = 'نام کاربری خالی است';
}
// بررسی شرایط خاص برای نام کاربری
elseif(strlen($username) < 8) {
    $error = 'نام کاربری باید حداقل ۸ کاراکتر باشد.';
}
elseif(!preg_match('/^[A-Za-z0-9]+$/', $username)) {
    $error = 'نام کاربری فقط باید شامل حروف انگلیسی و اعداد باشد (بدون فاصله یا کاراکتر فارسی).';
}
elseif(!preg_match('/[A-Za-z]/', $username) || !preg_match('/[0-9]/', $username)) {
    $error = 'نام کاربری باید شامل حداقل یک حرف انگلیسی و یک عدد باشد.';
}
elseif(empty($password)) {
    $error = 'رمز عبور خالی است';
}
elseif(strlen($password) < 8) {
    $error = 'کلمه عبور باید حداقل ۸ کاراکتر باشد.';
}
elseif(!preg_match('/^[A-Za-z0-9]+$/', $password)) {
    $error = ' کلمه عبور فقط باید شامل حروف انگلیسی و اعداد باشد (بدون فاصله یا کاراکتر فارسی).';
}
elseif(!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
    $error = ' کلمه عبور باید شامل حداقل یک حرف انگلیسی و یک عدد باشد.';
}
elseif(empty($mobile)) {
    $error = 'شماره موبایل خالی است';
}
else {
    $query = "SELECT * FROM users WHERE mobile = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$mobile]);
    $user = $stmt->fetch();

    if($user === false){
        $query = "INSERT INTO users (fullname, username, password, email, mobile) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $password = password_hash($password, PASSWORD_DEFAULT);
        $stmt->execute([$fullname, $username, $password, $email, $mobile]);

        if($stmt){
            $response = ['status' => true];
            $response['message'] = 'ثبت نام با موفقیت انجام شد';
        } else {
            $response = ['status' => false];
            $response['message'] = 'ثبت نام با موفقیت انجام نشد';
        }
    } else {
        $response = ['status' => false];
        $response['message'] = 'شماره موبایل قبلا ثبت شده است';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

if(isset($error)) {
    echo json_encode(['status' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
}
