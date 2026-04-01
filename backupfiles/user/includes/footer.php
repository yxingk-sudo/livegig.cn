</main>
        </div>
    </div>

    <script>
        // 侧边栏激活状态
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            const navLinks = document.querySelectorAll('.nav-link');
            
            navLinks.forEach(link => {
                if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href'))) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });

            // 调试导航点击
            console.log('Bootstrap JS loaded, dropdowns should work');
            
            // 确保下拉菜单正常工作
            const dropdowns = document.querySelectorAll('.dropdown-toggle');
            dropdowns.forEach(dropdown => {
                dropdown.addEventListener('click', function(e) {
                    console.log('Dropdown clicked:', this.textContent);
                });
            });
        });
    </script>
    <?php
    // 安全刷新输出缓冲，防止 headers already sent
    if (ob_get_level()) {
        @ob_end_flush();
    }
    ?>
</body>
</html>