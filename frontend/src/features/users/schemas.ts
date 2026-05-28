import { z } from 'zod'

const rutRegex = /^\d{7,8}-[0-9kK]$/

export const userCreateSchema = z
  .object({
    name: z.string().min(3, 'El nombre debe tener al menos 3 caracteres').max(150),
    rut: z.string().regex(rutRegex, 'RUT inválido (ej: 12345678-5)'),
    email: z.string().email('Email inválido').max(150),
    password: z.string().min(8, 'La contraseña debe tener al menos 8 caracteres'),
    password_confirmation: z.string(),
    health_center_id: z.number().nullable(),
    role: z.string().min(1, 'Debe seleccionar un rol'),
    is_active: z.boolean(),
  })
  .refine((data) => data.password === data.password_confirmation, {
    message: 'Las contraseñas no coinciden',
    path: ['password_confirmation'],
  })

export const userUpdateSchema = z
  .object({
    name: z.string().min(3).max(150).optional(),
    rut: z.string().regex(rutRegex, 'RUT inválido (ej: 12345678-5)').optional(),
    email: z.string().email('Email inválido').max(150).optional(),
    password: z
      .string()
      .min(8, 'La contraseña debe tener al menos 8 caracteres')
      .optional()
      .or(z.literal('')),
    password_confirmation: z.string().optional(),
    health_center_id: z.number().nullable().optional(),
    role: z.string().optional(),
    is_active: z.boolean().optional(),
  })
  .refine(
    (data) => {
      if (data.password && data.password_confirmation) {
        return data.password === data.password_confirmation
      }
      return true
    },
    {
      message: 'Las contraseñas no coinciden',
      path: ['password_confirmation'],
    }
  )

export type UserCreateFormData = z.infer<typeof userCreateSchema>
export type UserUpdateFormData = z.infer<typeof userUpdateSchema>
