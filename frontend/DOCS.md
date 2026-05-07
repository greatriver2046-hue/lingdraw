# Component Structure & Documentation

This document provides a detailed overview of the Vue components and their relationships in the project.

## Layout Architecture

### `MainLayout.vue`
- **Path**: `src/layout/MainLayout.vue`
- **Description**: The core layout component that defines the three-column structure.
- **Slots**:
  - `sidebar`: Left column (defaults to `AppSidebar`)
  - `default`: Center content area
  - `chat`: Right column (defaults to `ChatPanel`)
- **Structure**:
  - Flex container with fixed-width sidebars and flexible main content.
  - Responsive considerations handled via CSS (flexbox).

## Components

### 1. `AppSidebar.vue`
- **Path**: `src/components/AppSidebar.vue`
- **Description**: Navigation sidebar on the left.
- **State**:
  - `activeItem`: Tracks the currently selected menu item.
- **Features**:
  - Logo display
  - Navigation menu items (Generate, Gallery, Models, etc.)
  - Hover and active states for interactivity.

### 2. `ChatPanel.vue`
- **Path**: `src/components/ChatPanel.vue`
- **Description**: Interactive chat interface on the right.
- **Dependencies**: `useChatStore` (Pinia)
- **Features**:
  - Message history display
  - User and AI Assistant message differentiation
  - Auto-scroll to bottom on new messages
  - Input area with send button

### 3. `GenerationCanvas.vue` (View)
- **Path**: `src/views/GenerationCanvas.vue`
- **Description**: The main workspace for image generation.
- **Dependencies**: `useGenerationStore` (Pinia)
- **Features**:
  - Image display area
  - Loading state handling
  - Error state handling
  - Prompt input field with generate button

## State Management (Pinia)

### `chat.js`
- **Store ID**: `chat`
- **State**:
  - `messages`: Array of message objects `{ id, role, content, timestamp }`
- **Actions**:
  - `sendMessage(content)`: Adds user message and simulates AI response.

### `generation.js`
- **Store ID**: `generation`
- **State**:
  - `prompt`: Current prompt text
  - `isGenerating`: Boolean loading state
  - `generatedImage`: URL of the generated image
  - `error`: Error message if generation fails
- **Actions**:
  - `generateImage(prompt)`: Simulates async API call to generate image. Handles success/error states.

## Design System

- **Colors**:
  - Dark Sidebar: `#1a1c23`
  - Light Background: `#f0f2f5` / `#f5f7fa`
  - White Panels: `#ffffff`
  - Accent Blue: `#3478f6`
- **Typography**:
  - Sans-serif stack (Helvetica Neue, etc.)
- **Icons**:
  - Using `@element-plus/icons-vue`
