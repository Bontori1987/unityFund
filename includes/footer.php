<footer class="bg-white border-top mt-5 py-4">
    <div class="container">
        <div class="row align-items-center text-center text-md-start">
            <div class="col-md-4 mb-2 mb-md-0">
                <img src="<?= $basePath ?? '' ?>assets/logo.jpg" alt="UnityFund"
                     style="height:28px;width:auto;border-radius:4px;object-fit:contain;">
                <span class="text-muted ms-2 small">Fund what matters.</span>
            </div>
            <div class="col-md-4 mb-2 mb-md-0">
                <small class="text-muted">
                    &copy; <?= date('Y') ?> UnityFund. Advanced Database Systems Project.
                </small>
            </div>
            <div class="col-md-4 text-md-end">
                <a href="<?= $basePath ?? '' ?>top_donors.php" class="text-muted text-decoration-none small me-3">Top Donors</a>
                <a href="<?= $basePath ?? '' ?>running_total.php" class="text-muted text-decoration-none small">Progress</a>
            </div>
        </div>
    </div>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= $basePath ?? '' ?>assets/js/app.js"></script>
</body>
</html>
