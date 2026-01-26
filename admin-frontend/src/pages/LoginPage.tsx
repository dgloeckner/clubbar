/**
 * Login Page
 * Handles user authentication
 */

import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { LoginForm } from '../components/forms/LoginForm'
import { useAuth } from '../context/AuthContext'

export function LoginPage() {
  const navigate = useNavigate()
  const { login, loading, error } = useAuth()
  const [localError, setLocalError] = useState<string>()

  const handleSubmit = async (email: string, password: string) => {
    setLocalError(undefined)
    const success = await login({ email, password })
    if (success) {
      navigate('/members')
    } else {
      setLocalError(error || 'Login failed')
    }
  }

  return <LoginForm onSubmit={handleSubmit} loading={loading} error={localError} />
}
