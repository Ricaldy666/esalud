import { z } from 'zod'

export const healthCenterCreateSchema = z.object({
  name: z.string().min(3, 'El nombre debe tener al menos 3 caracteres').max(200),
  code_deis: z.string().min(1, 'El código DEIS es requerido').max(20),
  type: z.string().min(1, 'Debe seleccionar un tipo'),
  address: z.string().max(255).nullable().optional(),
  commune: z.string().max(100).nullable().optional(),
  is_active: z.boolean(),
})

export const healthCenterUpdateSchema = z.object({
  name: z.string().min(3).max(200).optional(),
  code_deis: z.string().min(1).max(20).optional(),
  type: z.string().optional(),
  address: z.string().max(255).nullable().optional(),
  commune: z.string().max(100).nullable().optional(),
  is_active: z.boolean().optional(),
})

export type HealthCenterCreateFormData = z.infer<typeof healthCenterCreateSchema>
export type HealthCenterUpdateFormData = z.infer<typeof healthCenterUpdateSchema>
