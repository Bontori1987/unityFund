<?php
    $conn = mysqli_connect("localhost", "root", "", "unityfund_db");

    if($conn->connect_error){
        die("Connection failed: " . $conn->connect_error);
    }

    //Lay ID
    $id = isset($_GET['id']) ? $_GET['id'] : 0;
    // echo "ID dang tim la: " . $id; // Dong nay de kiem tra thoi, sau khi chay duoc thi xoa di
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
        <link rel="stylesheet" type="text/css" href="campaign-detail.css">
        <link href="https://fonts.googleapis.com/css2?family=Nunito:ital,wght@0,200..1000;1,200..1000&display=swap" rel="stylesheet">
    </head>

    <body>

        <!-- NAV BAR  -->
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

        <!-- CAMPAIGN SECTION -->
        <div class="container">
            <h1 class="cam-Title text-start text-uppercase" id="campaignTitle">
               <?php echo $campaign['title']; ?>
            </h1>

            <!-- <img src="../<?php echo $campaign['image_path']; ?>" class="cam-img img-fluid"> -->
            <div class="campaign-card">
                <div class="share-preview">
                    <img src="../<?php echo $campaign['image_path']; ?>" class="cam-img img-fluid">
                    <a class="btn-donate">Donate now!</a>
                </div>
                
                <!-- <button class="btn-share">Share</button> -->
            </div>
            
            <!-- DOTS -->
             <div class="card-footer-nav">
                <div class="dot-nav" id="dotNav">
                    <div class="dot" onclick="setDot(0)"></div>
                    <div class="dot" onclick="setDot(1)"></div>
                    <div class="dot" onclick="setDot(2)"></div>
                    <div class="dot" onclick="setDot(3)"></div>
                    <div class="dot active" onclick="setDot(4)"></div>
                </div>

                <div class="nav-arrows">
                    <button class="nav-btn" onclick="prevDot()">&#8592;</button>
                    <button class="nav-btn" onclick="nextDot()">&#8594;</button>
                </div>
            </div>

            <!-- Share button -->
            <button class="btn-share" onclick="handleShare()">
            <svg viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            Share
            </button>
 
            <div class="description mt-4">
                <?php echo nl2br($campaign['description']); ?>
            </div>

            <!-- TOAST -->
            <div class="toast-msg" id="toastMsg">✓ Link đã được copy!</div>
            
        </div>
    </body>

    <script src="campaign.js"></script>
</html>
