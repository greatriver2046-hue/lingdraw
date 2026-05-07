# Vue Frontend Project

This project is a Vue 3 application that implements a "Generation Canvas" interface for AI image generation.

## Tech Stack

- **Vue 3**: Composition API
- **Vite**: Build tool
- **Element Plus**: UI Component Library
- **Pinia**: State Management
- **Vue Router**: Routing
- **Sass**: CSS Preprocessor
- **Vitest**: Unit Testing

## Project Structure

```
src/
├── assets/          # Static assets
├── components/      # Reusable components (AppSidebar, ChatPanel)
├── layout/          # Layout components (MainLayout)
├── router/          # Routing configuration
├── stores/          # Pinia stores (chat, generation)
├── views/           # Page views (GenerationCanvas)
├── App.vue          # Root component
└── main.js          # Entry point
```

## Setup & Run

1. **Install Dependencies**
   ```sh
   npm install
   ```

2. **Run Development Server**
   ```sh
   npm run dev
   ```

3. **Run Tests**
   ```sh
   npm run test
   ```

4. **Build for Production**
   ```sh
   npm run build
   ```

## Features

- **Responsive Layout**: Three-column layout with Sidebar, Canvas, and Chat.
- **Generation Canvas**: Image display area with prompt input.
- **Chat Interface**: Interactive chat panel with AI assistant simulation.
- **State Management**: 
  - `chat.js`: Manages chat history and simulation.
  - `generation.js`: Manages image generation state and prompt.
