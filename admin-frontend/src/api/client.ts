import axios from 'axios'
import type { AxiosRequestConfig } from 'axios'

// Stub — replaced in Task 3
const axiosInstance = axios.create({ baseURL: '/api', withCredentials: true })

export const customInstance = <T>(
  config: AxiosRequestConfig,
  options?: { signal?: AbortSignal }
): Promise<T> =>
  axiosInstance({ ...config, signal: options?.signal }).then(({ data }) => data)

export default axiosInstance
