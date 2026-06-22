import { useQuery } from '@tanstack/react-query'
import { remUploadsService } from '../services/rem-uploads'
import type { RemUploadFilters } from '../types/rem'

export const useRemUploads = (filters?: RemUploadFilters) => {
  return useQuery({
    queryKey: ['rem-uploads', filters],
    queryFn: () => remUploadsService.list(filters),
    staleTime: 30_000,
  })
}

export const useRemUpload = (id: number) => {
  return useQuery({
    queryKey: ['rem-uploads', id],
    queryFn: () => remUploadsService.get(id),
    enabled: !!id,
  })
}
