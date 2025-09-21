import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit } from '@angular/core';
import { RouterModule } from '@angular/router';
import { ConfirmationService, MessageService } from 'primeng/api';
import { ButtonModule } from 'primeng/button';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { IconField } from 'primeng/iconfield';
import { InputIcon } from 'primeng/inputicon';
import { InputTextModule } from 'primeng/inputtext';
import { TableModule } from 'primeng/table';
import { ToastModule } from 'primeng/toast';
import { lastValueFrom } from 'rxjs';

@Component({
  selector: 'app-tipo-atencion-list',
   imports: [
    CommonModule,
    TableModule,
    ButtonModule,
    ToastModule,
    ConfirmDialogModule,
    RouterModule,
    IconField,
    InputIcon,
    InputTextModule,
  ],
  providers: [ConfirmationService, MessageService],
  templateUrl: './tipo-atencion-list.html',
  styleUrl: './tipo-atencion-list.scss'
})
export class TipoAtencionList implements OnInit {
  tiposAtencionAll: any[] = [];
  loading: boolean = false;

  constructor(
    private apiService: ApiService,
    private messageService: MessageService,
    private confirmService: ConfirmationService
  ) {}

  ngOnInit(): void {
    this.cargarTiposAtencion();
  }

  async cargarTiposAtencion(): Promise<void> {
    this.loading = true;
    try {
      const obs = await this.apiService.get<any[]>('controllers/tipoatencion.controller.php?op=todos');
      const data = await lastValueFrom(obs);
      this.tiposAtencionAll = data.map(t => ({
        idTipoAtencion: t.idTipoAtencion,
        nombre: t.nombre,
        descripcion: t.descripcion,
        estado: t.estado === '1' ? 'Activo' : 'Inactivo',
        fechaCreacion: t.fechaCreacion,
        fechaActualizacion: t.fechaActualizacion
      }));
    } catch (error) {
      console.error(error);
      this.apiService.showToast('error', 'Error al cargar tipos de atención.', 'Error');
    } finally {
      this.loading = false;
    }
  }

  onGlobalFilter(dt: any, event: Event): void {
  const inputElement = event.target as HTMLInputElement;
  const value = inputElement?.value || '';
  dt.filterGlobal(value, 'contains');
}

  confirmEliminar(tipo: any): void {
    this.confirmService.confirm({
      message: `¿Está seguro que desea eliminar "${tipo.nombre}"?`,
      header: 'Confirmación',
      icon: 'pi pi-exclamation-triangle',
      accept: () => {
        this.eliminarTipo(tipo.idTipoAtencion);
      }
    });
  }

  async eliminarTipo(id: number): Promise<void> {
    try {
      const obs = await this.apiService.post('controllers/tipoatencion.controller.php?op=eliminar', { idTipoAtencion: id });
      await lastValueFrom(obs);
      this.apiService.showToast('success', 'Tipo de atención eliminado correctamente.','');
      this.cargarTiposAtencion();
    } catch (error) {
      this.apiService.showToast('error', 'No se pudo eliminar el tipo de atención.', 'Error');
    }
  }
}
