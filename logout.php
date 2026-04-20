<?php
session_start();
session_destroy();
header('Location: dev_login.php');
exit;
