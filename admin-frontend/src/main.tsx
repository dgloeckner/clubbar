/**
 * Application Entry Point
 * Bootstraps React and mounts the app
 */

import React from 'react'
import ReactDOM from 'react-dom/client'
import App from './App'
import { theme } from './styles/design-system'

// Global styles
const globalStyles = `
  * {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
  }

  html {
    scroll-behavior: smooth;
  }

  body {
    font-family: ${theme.typography.fontFamily.base};
    font-size: ${theme.typography.fontSize.base};
    line-height: ${theme.typography.lineHeight.normal};
    color: ${theme.colors.text.primary};
    background: ${theme.colors.bg.primary};
  }

  h1, h2, h3, h4, h5, h6 {
    font-weight: ${theme.typography.fontWeight.semibold};
  }

  a {
    color: ${theme.colors.semantic.primary};
    text-decoration: none;
  }

  a:hover {
    text-decoration: underline;
  }

  button:hover:not(:disabled) {
    cursor: pointer;
  }

  input:disabled, textarea:disabled {
    opacity: 0.6;
    cursor: not-allowed;
  }

  /* Scrollbar styling */
  ::-webkit-scrollbar {
    width: 8px;
    height: 8px;
  }

  ::-webkit-scrollbar-track {
    background: ${theme.colors.bg.secondary};
  }

  ::-webkit-scrollbar-thumb {
    background: ${theme.colors.border.light};
    border-radius: 4px;
  }

  ::-webkit-scrollbar-thumb:hover {
    background: ${theme.colors.border.dark};
  }
`

// Inject global styles
const styleSheet = document.createElement('style')
styleSheet.textContent = globalStyles
document.head.appendChild(styleSheet)

// Mount React app
ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <App />
  </React.StrictMode>
)
