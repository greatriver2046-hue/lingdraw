<template>
  <div class="home-container">

    <!-- Navbar -->
    <nav class="navbar">
      <div class="navbar-left">
        <div class="logo">
          <img v-if="appStore.siteConfig.logo" :src="appStore.siteConfig.logo" class="logo-img" alt="Logo" />
          <template v-else>
            <span class="logo-icon">{{ siteIconText }}</span>
            <span class="logo-text">{{ siteTitleText }}</span>
          </template>
        </div>
        <div class="nav-links">
          <a href="#" class="active" @click.prevent="router.push('/')">首页</a>
          <a href="#" @click.prevent="router.push('/creative')">创意</a>
        </div>
      </div>
      <div class="navbar-right">
        <template v-if="userStore.isLoggedIn">
          <el-dropdown trigger="click" @command="handleCommand">
            <div class="user-profile-trigger">
              <el-avatar :size="32" :src="userStore.userInfo.avatar" v-if="userStore.userInfo.avatar" />
              <el-avatar :size="32" :icon="UserFilled" v-else />
              <span class="username">{{ userStore.userInfo.nickname || userStore.userInfo.username || 'User' }}</span>
              <el-icon class="el-icon--right"><ArrowDown /></el-icon>
            </div>
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item command="creation-center">创作中心</el-dropdown-item>
                <el-dropdown-item command="logout" divided>退出登录</el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
        </template>
        <el-button v-else type="primary" class="signin-btn" @click="handleSignIn">登录</el-button>
      </div>
    </nav>

    <!-- Hero Section -->
    <section class="hero-section">
      <div class="hero-bg-container">
        <transition name="bg-fade">
          <div 
            :key="currentSlideIndex"
            class="hero-bg"
            :style="heroStyle"
          ></div>
        </transition>
      </div>
      <div class="hero-content">
        <transition name="fade" mode="out-in">
          <div :key="currentSlideIndex" class="slide-content">
            <h1 v-html="currentSlide.title || 'The design agent<br>更智能，更懂你'"></h1>
            <p>{{ currentSlide.description || 'Unlock the power of Qwen3. From the flagship Max for reasoning to Flash for speed—build with Alibaba\'s latest models.' }}</p>
            <el-button class="explore-btn" @click="handleStartNow">立即开始</el-button>
          </div>
        </transition>
      </div>
      <div class="hero-footer">
        <div class="hero-tabs">
          <div 
            v-for="(slide, index) in slides" 
            :key="index"
            class="hero-tab" 
            :class="{ active: currentSlideIndex === index }"
            @click="currentSlideIndex = index"
          >
            {{ slide.tab_text || `0${index + 1} Slide` }}
          </div>
        </div>
      </div>
    </section>
    <!-- Footer -->
    <footer class="footer">
      <div class="footer-top">
        <div class="footer-brand">
          <div class="logo">
            <img v-if="appStore.siteConfig.logo" :src="appStore.siteConfig.logo" class="logo-img" alt="Logo" />
            <span v-else class="logo-icon">{{ siteIconText }}</span>
            <span class="logo-text">{{ siteTitleText }}</span>
          </div>
          <p>{{ appStore.siteConfig.footer_text || '' }}</p>
          <div class="footer-btns">
            <el-button class="discord-btn"><el-icon><ChatLineRound /></el-icon> Join Discord</el-button>
            <el-button class="support-btn"><el-icon><Headset /></el-icon> Client Support</el-button>
          </div>
        </div>
        <div class="footer-links">
          <div class="link-group">
            <h5>MODELS</h5>
            <a href="#">Qwen Collection</a>
            <a href="#">Wan Collection</a>
          </div>
          <div class="link-group">
            <h5>RESOURCES</h5>
            <a href="#">Documentation</a>
            <a href="#">Explore</a>
            <a href="#">Contact Sales</a>
          </div>
          <div class="link-group">
            <h5>SOCIAL</h5>
            <a href="#">Discord</a>
          </div>
          <div class="link-group">
            <h5>PLAYGROUND</h5>
            <a href="#">What does it look like</a>
          </div>
        </div>
      </div>
      <div class="footer-bottom">
        <span>© {{ currentYear }} {{ siteTitleText }}</span>
        <div class="bottom-links">
          <a href="#">Privacy</a>
          <a href="#">Terms</a>
        </div>
      </div>
    </footer>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useUserStore } from '@/stores/user'
import { 
  ArrowRight, ArrowLeft, ArrowDown, Search, ChatLineRound, Headset, UserFilled 
} from '@element-plus/icons-vue'

const router = useRouter()
const appStore = useAppStore()
const userStore = useUserStore()
const currentSlideIndex = ref(0)
const siteTitleText = computed(() => appStore.siteConfig.site_title || appStore.siteConfig.site_name || 'AI生成平台')
const siteIconText = computed(() => {
  const title = String(siteTitleText.value || '').trim()
  return title ? title.slice(0, 1) : 'A'
})
const currentYear = new Date().getFullYear()

const slides = computed(() => {
  if (appStore.siteConfig.slides && Array.isArray(appStore.siteConfig.slides) && appStore.siteConfig.slides.length > 0) {
    return appStore.siteConfig.slides
  }
  // Default slides if no config
  return [
    {
      tab_text: '01 AI Platform for All Your Generative Media & LLM...',
      title: 'The design agent<br>更智能，更懂你',
      description: 'Unlock the power of Qwen3. From the flagship Max for reasoning to Flash for speed—build with Alibaba\'s latest models.',
      image: '' // Use default CSS bg
    },
    {
      tab_text: '02 Qwen3 Series: Think Deeper, Act Faster',
      title: 'Qwen3 Series<br>Think Deeper, Act Faster',
      description: 'Experience the next generation of reasoning models.',
      image: ''
    },
    {
      tab_text: '03 Wan Models: 2.1 Video & Image Generation',
      title: 'Wan Models 2.1',
      description: 'State-of-the-art video and image generation capabilities.',
      image: ''
    },
    {
      tab_text: '04 Enterprise-Grade & Infrastructure',
      title: 'Enterprise Infrastructure',
      description: 'Reliable, scalable, and secure AI infrastructure for your business.',
      image: ''
    }
  ]
})

const currentSlide = computed(() => slides.value[currentSlideIndex.value] || slides.value[0])

const heroStyle = computed(() => {
  const img = currentSlide.value.image
  if (img) {
    return {
      backgroundImage: `linear-gradient(rgba(5, 10, 21, 0.3), rgba(5, 10, 21, 0.3)), url('${img}')`,
      backgroundBlendMode: 'normal',
      backgroundSize: 'cover',
      backgroundPosition: 'center'
    }
  }
  return {} // Fallback to CSS default
})

const handleSignIn = () => {
  router.push('/login')
}

const handleStartNow = () => {
  if (userStore.isLoggedIn) {
    router.push('/creation/general')
  } else {
    router.push('/login')
  }
}

const handleCommand = (command) => {
  if (command === 'logout') {
    userStore.logout()
  } else if (command === 'creation-center') {
    router.push('/creation/general')
  }
}


</script>

<style scoped lang="scss">
.home-container {
  font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  color: #1a1a1a;
  background-color: #fff;
  overflow-x: hidden;
}



.navbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 40px;
  border-bottom: 1px solid #f0f0f0;
  background: #fff;
  position: sticky;
  top: 0;
  z-index: 1000;

  .navbar-left {
    display: flex;
    align-items: center;
    gap: 40px;
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 18px;
    
    .logo-icon {
      background: #000;
      color: #fff;
      width: 24px;
      height: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 0;
      font-size: 14px;
    }

    .logo-img {
      height: 32px;
      object-fit: contain;
    }
  }

  .nav-links {
    display: flex;
    gap: 24px;
    
    a, .nav-dropdown {
      color: #666;
      text-decoration: none;
      font-size: 14px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 4px;
      cursor: pointer;
      
      &:hover,
      &.active {
        color: #000;
      }
    }
  }

  .navbar-right {
    display: flex;
    align-items: center;
    gap: 20px;
    
    .search-icon {
      font-size: 18px;
      color: #666;
      cursor: pointer;
    }
    
    .signin-btn {
      border-radius: 0;
      padding: 8px 20px;
      font-weight: 600;
      background-color: #111;
      border-color: #111;
      color: #fff;

      &:hover,
      &:focus {
        background-color: #000;
        border-color: #000;
        color: #fff;
      }
    }

    .user-profile-trigger {
      display: flex;
      align-items: center;
      gap: 8px;
      cursor: pointer;
      padding: 4px 8px;
      border-radius: 4px;
      transition: background-color 0.2s;

      &:hover {
        background-color: #f5f7f9;
      }

      .username {
        font-size: 14px;
        font-weight: 500;
        color: #333;
      }
    }
  }
}

.hero-section {
  background: radial-gradient(circle at 70% 50%, rgba(0, 255, 200, 0.15), transparent 50%),
              radial-gradient(circle at 20% 80%, rgba(0, 100, 255, 0.2), transparent 50%),
              #050a15;
  background-image: url('https://images.unsplash.com/photo-1620641788421-7a1c342ea42e?auto=format&fit=crop&q=80&w=1920'), // Dark abstract AI-like bg
                    linear-gradient(rgba(5, 10, 21, 0.7), rgba(5, 10, 21, 0.7));
  background-blend-mode: overlay;
  background-size: cover;
  background-position: center;
  height: 850px;
  color: #fff;
  display: flex;
  flex-direction: column;
  justify-content: center;
  padding: 0 80px;
  position: relative;
  overflow: hidden;
  
  .hero-bg-container {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 0;
    
    .hero-bg {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      height: 100%;
      background-size: cover;
      background-position: center;
    }
  }

  &::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-image: radial-gradient(rgba(255, 255, 255, 0.2) 1.5px, transparent 1.5px);
    background-size: 5px 5px;
    pointer-events: none;
    z-index: 1;
  }

  .hero-content {
    position: relative;
    z-index: 10;
    pointer-events: auto;
  }

  h1 {
    font-size: 48px;
    line-height: 1.2;
    margin-bottom: 16px;
    font-weight: 700;
  }

  p {
    font-size: 18px;
    max-width: 600px;
    margin-bottom: 32px;
    opacity: 0.9;
  }

  .explore-btn {
    width: fit-content;
    padding: 12px 24px;
    font-weight: 600;
    border-radius: 0;
    background: #fff;
    color: #000;
    border: none;
  }

  .hero-footer {
    position: absolute;
    bottom: 0;
    left: 0;
    right: 0;
    background: rgba(0,0,0,0.3);
    backdrop-filter: blur(10px);
    z-index: 2;
    
    .hero-tabs {
      display: flex;
      padding: 0 40px;
      
      .hero-tab {
        flex: 1;
        padding: 16px;
        font-size: 12px;
        opacity: 0.6;
        border-top: 2px solid transparent;
        cursor: pointer;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        
        &.active {
          opacity: 1;
          border-top-color: #fff;
        }
        
        &:hover {
          opacity: 1;
        }
      }
    }
  }
}



.section {
  padding: 80px 80px;
  max-width: 1440px;
  margin: 0 auto;
  
  .section-header {
    margin-bottom: 48px;
    
    .tag {
      font-size: 11px;
      font-weight: 800;
      background: #000;
      color: #fff;
      padding: 4px 10px;
      border-radius: 0;
      margin-bottom: 12px;
      display: inline-block;
      letter-spacing: 1px;
    }
    
    h2 {
      font-size: 40px;
      margin: 8px 0;
      font-weight: 700;
      letter-spacing: -0.5px;
    }
    
    p {
      color: #666;
      font-size: 16px;
    }
  }
}

.footer {
  background: #f9fbff;
  padding: 80px 80px 40px;
  border-top: 1px solid #eee;

  .footer-top {
    display: grid;
    grid-template-columns: 1.5fr 2fr;
    gap: 80px;
    margin-bottom: 60px;
  }

  .footer-brand {
    .logo {
      display: flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      margin-bottom: 20px;
      
      .logo-img {
        height: 30px;
        object-fit: contain;
      }

      .logo-icon { background: #000; color: #fff; padding: 4px 8px; border-radius: 0; }
    }
    p { color: #666; font-size: 14px; line-height: 1.6; margin-bottom: 32px; }
    .footer-btns {
      display: flex;
      gap: 12px;
      .el-button {
        border-radius: 0;
        padding: 10px 20px;
        font-weight: 600;
      }
    }
  }

  .footer-links {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 40px;
    
    h5 { font-size: 12px; color: #999; margin-bottom: 20px; }
    a { display: block; color: #333; text-decoration: none; font-size: 14px; margin-bottom: 12px; font-weight: 500; }
  }

  .footer-bottom {
    display: flex;
    justify-content: space-between;
    padding-top: 40px;
    border-top: 1px solid #eee;
    font-size: 12px;
    color: #999;
    
    .bottom-links {
      display: flex;
      gap: 24px;
      a { color: #999; text-decoration: none; }
    }
  }
}

@media (max-width: 1024px) {
  .hero-section { padding: 0 40px; }
  .section { padding: 40px; }
  .footer { padding: 40px; }
  .footer-top { grid-template-columns: 1fr; gap: 40px; }
}

@media (max-width: 768px) {
  .navbar { padding: 16px 20px; .nav-links { display: none; } }
  .hero-section { h1 { font-size: 32px; } }
}
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.5s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}

.bg-fade-enter-active,
.bg-fade-leave-active {
  transition: opacity 1s ease;
}

.bg-fade-enter-from,
.bg-fade-leave-to {
  opacity: 0;
}
</style>
