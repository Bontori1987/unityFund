<?php
    $conn = mysqli_connect("localhost", "root", "", "unityfund_db");
    if($conn->connect_error){ die("Connection failed: " . $conn->connect_error); }

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $sql = "SELECT * FROM campaigndetail WHERE id = $id";
    $result = mysqli_query($conn, $sql);
    $campaign = mysqli_fetch_assoc($result);
    if(!$campaign){ die("Campaign not found"); }

    $sql_imgs = "SELECT img_path FROM campaign_images 
                 WHERE campaign_id = $id 
                 ORDER BY sort_order ASC";
    $result_imgs = mysqli_query($conn, $sql_imgs);
    if($result_imgs === false){ die("Lỗi query ảnh: " . mysqli_error($conn)); }

    $images = mysqli_fetch_all($result_imgs, MYSQLI_ASSOC);
    if(empty($images)){
        $images = [['img_path' => 'picture/default.jpg']];
    }
?>
<!DOCTYPE html>
<html>
<head>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="campaign-detail1.css">
    <link href="https://fonts.googleapis.com/css2?family=Nunito:ital,wght@0,200..1000;1,200..1000&display=swap" rel="stylesheet">
</head>
<body>

    <!-- NAV BAR -->
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
        <h1 class="cam-Title text-start text-uppercase text-center" id="campaignTitle">
            <?php echo $campaign['title']; ?>
        </h1>

        <!-- SLIDER ẢNH -->

        <div class="infor-sec">
            <div class="share-preview">
                <?php foreach($images as $i => $img): ?>
                    <img 
                        src="../<?php echo $img['img_path']; ?>"
                        class="slide-img <?php echo $i === 0 ? 'active' : ''; ?>"
                        data-index="<?php echo $i; ?>"
                    >
                <?php endforeach; ?>

                <!-- DONATE-->
                <!-- <a class="btn btn-outline-light btn-donate">Donate now!</a> -->
                <button type="button" class="btn btn-donate">Donate now!</button>
            </div>

            <!-- DOTS + ARROWS -->
            <div class="card-footer-nav">
                <div class="dot-nav" id="dotNav">
                    <?php foreach($images as $i => $img): ?>
                        <div class="dot <?php echo $i === 0 ? 'active' : ''; ?>"
                            onclick="setDot(<?php echo $i; ?>)">
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="nav-arrows">
                    <button class="nav-btn" onclick="prevDot()">&#8592;</button>
                    <button class="nav-btn" onclick="nextDot()">&#8594;</button>
                </div>
            </div>

            <div class="description mt-4">
                <?php echo nl2br($campaign['description']); ?>
            </div>
        </div>

        <!-- SHARE -->
        <button class="btn-share" onclick="handleShare()">
            <svg viewBox="0 0 24 24"><path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/><polyline points="16 6 12 2 8 6"/><line x1="12" y1="2" x2="12" y2="15"/></svg>
            Share
        </button>

        <!-- <div class="description mt-4">
            <?php echo nl2br($campaign['description']); ?>
        </div> -->

        <div class="toast-msg" id="toastMsg">✓ Link đã được copy!</div>
    </div>

    <script src="campaign1.js"></script>
</body>
</html>
