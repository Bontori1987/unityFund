<?php
    $conn = mysqli_connect("localhost", "root", "", "unityfund_db");

    if($conn->connect_error){
        die("Connection failed: " . $conn->connect_error);
    }

    //Lay ID
    $id = isset($_GET['id']) ? $_GET['id'] : 0;
    echo "ID dang tim la: " . $id; // Dong nay de kiem tra thoi, sau khi chay duoc thi xoa di
    $sql = "SELECT * FROM campaigndetail WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    $campaign =  mysqli_fetch_assoc($result);

    if(!$campaign){
        die("Campaign not found");
        exit;
    }

?>
<!DOCTYPE html>
<html> 
    <head>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" type="text/css" href="index.css">
        <title>
            <?php echo $campaign['title'];?> Unity Fund
        </title> 
    </head>

    <body>
        <header>
            <nav class="navbar navbar-light bg-white sticky-top border-bottom px-3">
                <div class="container-fluid d-flex justify-content-between align-items-center">
                    <i class="bi bi-search fs-4"></i>
                    <a class="navbar-brand fw-bold text-success fs-3" href="#">Unity Fund</a>
                    <button class="navbar-toggler border-0" type="button">
                        <span class="navbar-toggler-icon"></span>
                    </button>
                </div>
        </nav>
        </header>

        <div class="container">
            <h1>
               <?php echo $campaign['title']; ?>
            </h1>

            <img src="../<?php echo $campaign['image_path']; ?>" class="img-fluid">

            <div class="description mt-4">
                <?php echo nl2br($campaign['description']); ?>
            </div>
        </div>
    </body>
</html>