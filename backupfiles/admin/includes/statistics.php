<?php
// 统计信息组件
if (!isset($statistics) || !isset($reports)) return;
?>

<!-- 统计概览 -->
<div class="row mb-4">
    <div class="col-12 mb-3">
        <div class="card bg-light stat-card">
            <div class="card-header bg-dark text-white">
                <h5 class="mb-0"><i class="bi bi-graph-up"></i> 统计概览</h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-3">
                        <h3 class="text-primary mb-1"><?= number_format($statistics['basic']['total_bookings'] ?? 0) ?></h3>
                        <small class="text-muted">总预订次数</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-success mb-1"><?= number_format($statistics['basic']['total_booked_rooms'] ?? 0) ?></h3>
                        <small class="text-muted">总房间数</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-info mb-1"><?= number_format($statistics['basic']['total_checkins'] ?? 0) ?></h3>
                        <small class="text-muted">总入住人次</small>
                    </div>
                    <div class="col-md-3">
                        <h3 class="text-warning mb-1"><?= number_format($statistics['basic']['total_room_nights'] ?? 0) ?></h3>
                        <small class="text-muted">总房晚数</small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 各酒店统计 -->
<?php if (!empty($statistics['hotels'])): ?>
<div class="row mb-4">
    <?php foreach ($statistics['hotels'] as $hotel): ?>
        <div class="col-md-6 mb-3">
            <div class="card stat-card">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><?= $hotel['hotel_name'] ?></h6>
                </div>
                <div class="card-body">
                    <div class="row text-center">
                        <div class="col-3">
                            <h5 class="text-primary mb-1"><?= number_format($hotel['total_bookings'] ?? 0) ?></h5>
                            <small class="text-muted">预订次数</small>
                        </div>
                        <div class="col-3">
                            <h5 class="text-success mb-1"><?= number_format($hotel['total_booked_rooms'] ?? 0) ?></h5>
                            <small class="text-muted">房间数</small>
                        </div>
                        <div class="col-3">
                            <h5 class="text-info mb-1"><?= number_format($hotel['total_checkins'] ?? 0) ?></h5>
                            <small class="text-muted">入住人次</small>
                        </div>
                        <div class="col-3">
                            <h5 class="text-warning mb-1"><?= number_format($hotel['total_room_nights'] ?? 0) ?></h5>
                            <small class="text-muted">总房晚</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>