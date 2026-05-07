<template>
  <div class="account-settings">
    <el-card class="box-card" shadow="never">
      <el-form label-width="120px" class="settings-form">
        <el-form-item label="当前账号">
          <el-input disabled value="admin" />
        </el-form-item>
        <el-form-item label="原密码">
          <el-input type="password" show-password placeholder="请输入原密码" />
        </el-form-item>
        <el-form-item label="新密码">
          <el-input type="password" show-password placeholder="请输入新密码" />
        </el-form-item>
        <el-form-item label="确认新密码">
          <el-input type="password" show-password placeholder="请再次输入新密码" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary">修改密码</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup>
</script>

<style scoped>
.account-settings {
  padding: 20px;
}
.box-card {
  border: none; /* Flat design */
  background-color: transparent;
}
.box-card :deep(.el-card__body) {
  background-color: #fff;
  padding: 30px;
  border-radius: 4px;
}
.box-card :deep(.el-card__header) {
  border-bottom: none;
  padding: 0 0 20px 0;
}

.card-header span {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.settings-form {
  max-width: 600px;
}
</style>
