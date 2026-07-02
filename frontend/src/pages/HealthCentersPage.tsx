import { useState, useCallback } from 'react'
import { Plus } from 'lucide-react'
import { PageHeader } from '@/shared/components/PageHeader'
import { ConfirmDialog } from '@/shared/components/ConfirmDialog'
import {
  useHealthCenters,
  useCreateHealthCenter,
  useUpdateHealthCenter,
  useDeleteHealthCenter,
} from '@/features/health-centers'
import { HealthCentersTable } from '@/features/health-centers/components/HealthCentersTable'
import { HealthCenterDialog } from '@/features/health-centers/components/HealthCenterDialog'
import type { HealthCenter } from '@/features/health-centers/types'
import type {
  HealthCenterCreateFormData,
  HealthCenterUpdateFormData,
} from '@/features/health-centers/schemas'

export default function HealthCentersPage() {
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [selectedCenter, setSelectedCenter] = useState<HealthCenter | null>(null)

  const { data, isLoading } = useHealthCenters({ search, page })
  const createCenter = useCreateHealthCenter()
  const updateCenter = useUpdateHealthCenter()
  const deleteCenter = useDeleteHealthCenter()

  const handleOpenCreate = useCallback(() => {
    setSelectedCenter(null)
    setDialogOpen(true)
  }, [])

  const handleOpenEdit = useCallback((center: HealthCenter) => {
    setSelectedCenter(center)
    setDialogOpen(true)
  }, [])

  const handleOpenDelete = useCallback((center: HealthCenter) => {
    setSelectedCenter(center)
    setDeleteDialogOpen(true)
  }, [])

  const handleSubmit = useCallback(
    (formData: HealthCenterCreateFormData | HealthCenterUpdateFormData) => {
      if (selectedCenter) {
        updateCenter.mutate(
          { id: selectedCenter.id, data: formData as HealthCenterUpdateFormData },
          {
            onSuccess: () => setDialogOpen(false),
          }
        )
      } else {
        createCenter.mutate(formData as HealthCenterCreateFormData, {
          onSuccess: () => setDialogOpen(false),
        })
      }
    },
    [selectedCenter, createCenter, updateCenter]
  )

  const handleDelete = useCallback(() => {
    if (!selectedCenter) return
    deleteCenter.mutate(selectedCenter.id, {
      onSuccess: () => {
        setDeleteDialogOpen(false)
        setSelectedCenter(null)
      },
    })
  }, [selectedCenter, deleteCenter])

  const isMutating = createCenter.isPending || updateCenter.isPending || deleteCenter.isPending

  return (
    <div>
      <PageHeader
        title="Centros de Salud"
        description="Gestión de centros de salud"
        actions={
          <button
            onClick={handleOpenCreate}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg shadow-sm transition-colors"
          >
            <Plus className="w-4 h-4" />
            Nuevo Centro
          </button>
        }
      />

      <HealthCentersTable
        data={data?.data ?? []}
        loading={isLoading}
        pagination={data?.meta}
        onPageChange={setPage}
        search={search}
        onSearch={(value) => {
          setSearch(value)
          setPage(1)
        }}
        onEdit={handleOpenEdit}
        onDelete={handleOpenDelete}
      />

      <HealthCenterDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        center={selectedCenter}
        onSubmit={handleSubmit}
        loading={isMutating}
      />

      <ConfirmDialog
        open={deleteDialogOpen}
        onConfirm={handleDelete}
        onCancel={() => setDeleteDialogOpen(false)}
        title="Eliminar Centro de Salud"
        description={`¿Estás seguro de eliminar ${selectedCenter?.name}?`}
        confirmText="Eliminar"
        variant="destructive"
        loading={isMutating}
      />
    </div>
  )
}
