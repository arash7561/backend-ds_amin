<?php

require_once '../../db_connection.php';
header('Content-Type: application/json');
$json = file_get_contents('php://input');
$data = json_decode($json);

$name = $data->name;
$description = $data->description;
$slug = $data->slug;
$parent_id = $data->parent_id;
$status = $data->status;

$error;

if(empty($name)) {
    $error = 'نام خالی است';
}
elseif(empty($description)) {
    $error = 'توضیحات خالی است';
}
elseif(empty($slug)) {
    $error = 'اسلاگ خالی است';
}

else {
    $query = "SELECT * FROM categories WHERE slug = ?";
    $stmt = $conn->prepare($query);
    $stmt->execute([$slug]);
    $category = $stmt->fetch();

    if($category  === false){
        $query = "INSERT INTO categories (name, description, slug, parent_id, status) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([$name, $description, $slug, $parent_id, $status]);

        if($stmt){
            $response = ['status' => true];
            $response['message'] = 'دسته بندی با موفقیت انجام شد';
        } else {
            $response = ['status' => false];
            $response['message'] = 'دسته بندی با موفقیت انجام نشد';
        }
    } else {
        $response = ['status' => false];
        $response['message'] = 'اسلاگ قبلا ثبت شده است';
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

if(isset($error)) {
    echo json_encode(['status' => false, 'message' => $error], JSON_UNESCAPED_UNICODE);
}
