admin/batch_add_personnel_step2.php:        fetch('api_get_departments.php?project_id=' + projectId)
admin/batch_add_personnel_step2.php:        fetch('api_get_departments.php?project_id=' + projectId)
admin/batch_add_personnel_step2.php:        fetch('api_get_projects.php?company_id=' + companyId)
admin/batch_add_personnel_step2.php:        fetch('api_get_projects.php?company_id=' + companyId)
admin/bom_detector.php:    'get_company_projects_strict.php',
admin/bom_detector.php:    'get_personnel_projects_strict.php',
admin/deep_error_detector.php:    'admin/get_company_projects_strict.php',
admin/deep_error_detector.php:    'admin/get_company_projects_strict.php' => '严格版公司项目',
admin/deep_error_detector.php:    'admin/get_personnel_projects_strict.php',
admin/deep_error_detector.php:    'admin/get_personnel_projects_strict.php' => '严格版人员项目',
admin/deep_error_detector.php:    'api/get_company_projects.php'
admin/deep_error_detector.php:    'api/get_company_projects.php' => '原始API公司项目'
admin/deep_error_detector.php:    'api/get_personnel_projects.php',
admin/deep_error_detector.php:    'api/get_personnel_projects.php' => '原始API人员项目',
admin/edit_transportation.php:            fetch('get_all_personnel.php', {
admin/personnel_statistics_fixed.php:        fetch('get_person_details_enhanced.php?id=' + personId)
admin/personnel_statistics.php:        fetch(`api/departments/get_departments_by_project.php?project_id=${projectId}`)
admin/php_error_simulator.php:    __DIR__ . '/get_company_projects_strict.php',
admin/php_error_simulator.php:    __DIR__ . '/get_personnel_projects_strict.php',
admin/php_error_simulator.php:echo '<li><a href="get_company_projects_strict.php?company_id=1" target="_blank">直接访问公司项目API</a></li>';
admin/php_error_simulator.php:echo '<li><a href="get_personnel_projects_strict.php?personnel_id=1" target="_blank">直接访问人员项目API</a></li>';
admin/realtime_error_tracer.php:    $baseUrl . '/admin/get_company_projects_strict.php?company_id=1' => '公司项目API',
admin/realtime_error_tracer.php:    $baseUrl . '/admin/get_personnel_projects_strict.php?personnel_id=1' => '人员项目API',
admin/realtime_error_tracer.php:    $baseUrl . '/api/get_personnel_projects.php?personnel_id=1' => '原始人员项目API',
admin/runtime_error_catcher.php:            'http://localhost/admin/get_company_projects_strict.php?company_id=1'
admin/runtime_error_catcher.php:            'http://localhost/admin/get_personnel_projects_strict.php?personnel_id=1'
admin/runtime_error_catcher.php:            'http://localhost/admin/get_personnel_projects_strict.php?personnel_id=1'
admin/test_api.php:include 'get_person_details_enhanced.php';
admin/validate_api.php:    $baseUrl . '/api/get_personnel_projects.php?personnel_id=1',
