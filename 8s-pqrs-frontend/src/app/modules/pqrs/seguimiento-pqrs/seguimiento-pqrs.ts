import { ApiService } from '@/modules/Services/api-service';
import { CommonModule } from '@angular/common';
import { Component, OnInit, ViewChild } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { ConfirmationService, MessageService } from 'primeng/api';
import { AvatarModule } from 'primeng/avatar';
import { ButtonModule } from 'primeng/button';
import { CardModule } from 'primeng/card';
import { ConfirmDialogModule } from 'primeng/confirmdialog';
import { DividerModule } from 'primeng/divider';
import { ProgressSpinnerModule } from 'primeng/progressspinner';
import { TagModule } from 'primeng/tag';
import { TimelineModule } from 'primeng/timeline';
import { ToastModule } from 'primeng/toast';
import { ToolbarModule } from 'primeng/toolbar';
import { lastValueFrom } from 'rxjs';
import { IseguimientoPqrs } from '../model/Iseguimiento-pqrs';
import { IPqr } from '../model/Ipqr';
import { ScrollPanelModule } from 'primeng/scrollpanel';
import { TextareaModule } from 'primeng/textarea';
import { SelectModule } from 'primeng/select';
import { FileUploadModule } from 'primeng/fileupload';
import { FormBuilder, FormGroup, FormsModule, ReactiveFormsModule, Validators } from '@angular/forms';
import { QuillModules, QuillEditorComponent } from 'ngx-quill';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import { DialogModule } from 'primeng/dialog';
import Quill from 'quill';
import { TooltipModule } from 'primeng/tooltip';
import { Accordion, AccordionModule } from 'primeng/accordion';

@Component({
    selector: 'app-seguimiento-pqrs',
    imports: [
        FormsModule,
        ToolbarModule,
        CardModule,
        ButtonModule,
        DividerModule,
        TagModule,
        CommonModule,
        ToastModule,
        ConfirmDialogModule,
        TimelineModule,
        AvatarModule,
        ProgressSpinnerModule,
        ScrollPanelModule,
        TextareaModule,
        SelectModule,
        ReactiveFormsModule,
        QuillEditorComponent,
        DialogModule,
        TooltipModule,
        AccordionModule,

    ],
    providers: [
        ConfirmationService,
    ],
    templateUrl: './seguimiento-pqrs.html',
    styleUrl: './seguimiento-pqrs.scss'
})
export class SeguimientoPqrs implements OnInit {
    // public idUsuario: string = localStorage.getItem('idUsuario') || '';
    idUsuario: number = 2;
    public idRol: string = localStorage.getItem('idRol') || '';
    pqr: IPqr | null = null;
    loading = false;

    seguimientos: IseguimientoPqrs[] = [];
    loadingSeg = false;

    formSeguimiento!: FormGroup;
    savingSeg = false;
    dialogSeguimientoVisible = false;

    // Visualizar imagenes en grande
    previewVisible = false;
    previewSrc: string | null = null;
    esAsignacion = false;

    @ViewChild(QuillEditorComponent) quillCmp!: QuillEditorComponent;

    // Opcional: limita tamaño y reescala para no inflar la DB
    private readonly MAX_W = 1024;   // px
    private readonly MAX_H = 1024;   // px
    private readonly JPEG_Q = 0.85;  // 0..1

    dialogEscalarVisible = false;
    responsablesOptions: Array<{ label: string; value: number }> = [];
    responsableSeleccionado: number | null = null;
    savingEscalar = false;
    intentoEscalar = false;


    constructor(
        private route: ActivatedRoute,
        private router: Router,
        private apiService: ApiService,
        private messageService: MessageService,
        private confirmationService: ConfirmationService,
        private fb: FormBuilder,
        private sanitizer: DomSanitizer,

    ) { }

    async ngOnInit(): Promise<void> {
        // this.idRol = '1';
        const idParam = this.route.snapshot.paramMap.get('id') ?? this.route.snapshot.paramMap.get('idPqrs');
        const idPqrs = Number(idParam || 0);
        console.log('idPqrs:', idPqrs);

        if (!idPqrs) {
            this.messageService.add({ severity: 'warn', summary: 'Aviso', detail: 'Identificador de PQR no válido.' });
            this.onBack();
            return;
        }

        this.crearFormSeguimiento();
        await this.cargarPqr(idPqrs);
    }

    /** Carga la información del PQR desde el backend */
    async cargarPqr(idPqrs: number): Promise<void> {
        this.loading = true;
        try {
            const url = `controllers/pqrs.controller.php?op=uno&idPqrs=${idPqrs}`;
            const resp$ = await this.apiService.get<IPqr>(url);
            const data = await lastValueFrom(resp$);

            if (!data) {
                this.messageService.add({ severity: 'info', summary: 'Sin datos', detail: 'No se encontró información del PQR.' });
                this.onBack();
                return;
            }

            // Normaliza nombreCliente si no viene directamente desde el SELECT
            if (!data.nombreCliente && (data.nombres || data.apellidos)) {
                (data as any).nombreCliente = `${data.nombres ?? ''} ${data.apellidos ?? ''}`.trim();
            }

            this.pqr = data;
            console.log('PQR cargado:', this.pqr);
            await this.cargarSeguimientos(this.pqr!.idPqrs);
        } catch (err: any) {
            console.error('Error cargando PQR:', err);
            const msg = err?.error?.message || 'No se pudo cargar la información del PQR.';
            this.messageService.add({ severity: 'error', summary: 'Error', detail: msg });
            this.onBack();
        } finally {
            this.loading = false;
        }
    }

    onBubbleClick(ev: MouseEvent) {
        const el = ev.target as HTMLElement;
        if (el?.tagName === 'IMG') {
            this.previewSrc = (el as HTMLImageElement).src;
            this.previewVisible = true;
        }
    }

    /** Severidad visual del Tag según nombreEstado (para el HTML si deseas usarlo) */
    getEstadoSeverity(nombreEstado?: string): 'success' | 'info' | 'warning' | 'danger' {
        const val = (nombreEstado || '').toLowerCase();
        if (val.includes('cerr')) return 'success';
        if (val.includes('escala')) return 'danger';
        if (val.includes('abiert') || val.includes('pend')) return 'warning';
        return 'info';
    }

    /** Navegación */
    onBack(): void {
        this.router.navigate(['/pqrs']);
    }

    onPrint(): void {
        window.print();
    }

    /** Cierre del PQR (placeholder con confirmación) */
    confirmarCerrar(pqr: any, idEstado: any): void {
        const idPqrs = pqr?.idPqrs;
        if (!idPqrs) return;
        this.confirmationService.confirm({
            header: 'Cerrar PQR',
            message: `Está seguro de cerrar el PQR #${pqr.idPqrs}?`,
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí, cerrar',
            rejectLabel: 'Cancelar',
            accept: async () => {
                await this.cerrarPqr(idPqrs, idEstado);
            },
        });
    }

    confirmarAbrir(pqr: any, idEstado: any): void {
        const idPqrs = pqr?.idPqrs;
        if (!idPqrs) return;
        this.confirmationService.confirm({
            header: 'Abrir PQR',
            message: `Está seguro de abrir el PQR #${pqr.idPqrs}?`,
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí, abrir',
            rejectLabel: 'Cancelar',
            accept: async () => {
                await this.abrirPqr(idPqrs, idEstado);
            },
        });
    }

    private async cerrarPqr(idPqrs: number, idEstado: number): Promise<void> {
        try {
            const fd = new FormData();
            fd.append('idPqrs', String(idPqrs));
            fd.append('idEstado', String(idEstado));

            const resp = await (
                await this.apiService.post('controllers/pqrs.controller.php?op=actualizar_estado_cierre', fd)
            ).toPromise();

            if ((resp as any)?.success) {
                this.messageService.add({ severity: 'success', summary: 'PQRS', detail: 'Cerrado correctamente.' });
            } else {
                this.messageService.add({ severity: 'warn', summary: 'PQRS', detail: (resp as any)?.message || 'No se pudo cerrar.' });
            }


            this.messageService.add({ severity: 'success', summary: 'PQR', detail: `PQR #${idPqrs} cerrado.` });
            this.insertarSeguimiento(idPqrs, this.idUsuario, 'PQR Cerrado.');
            // Opcionalmente recargar para ver el nuevo estado:
            await this.cargarPqr(idPqrs);
            await this.cargarSeguimientos(idPqrs);
        } catch (e) {
            console.error(e);
            this.messageService.add({ severity: 'error', summary: 'Error', detail: 'No se pudo cerrar el PQR.' });
        }
    }

    private async abrirPqr(idPqrs: number, idEstado: number): Promise<void> {
        try {
            const fd = new FormData();
            fd.append('idPqrs', String(idPqrs));
            fd.append('idEstado', String(idEstado));

            const resp = await (
                await this.apiService.post('controllers/pqrs.controller.php?op=actualizar_estado_cierre', fd)
            ).toPromise();

            if ((resp as any)?.success) {
                this.messageService.add({ severity: 'success', summary: 'PQRS', detail: 'Abierto correctamente.' });
                this.messageService.add({ severity: 'success', summary: 'PQR', detail: `PQR #${idPqrs} abierto.` });
                this.esAsignacion = true;
                this.dialogEscalarVisible = true;
                this.intentoEscalar = false;
                this.responsableSeleccionado = null;
                this.cargarResponsables(0); // recarga todos los responsables

                // recargar para ver el nuevo estado:
                await this.cargarPqr(idPqrs);
                await this.cargarSeguimientos(idPqrs);
            } else {
                this.messageService.add({ severity: 'warn', summary: 'PQRS', detail: (resp as any)?.message || 'No se pudo abrir el PQRS.' });
            }


            this.insertarSeguimiento(idPqrs, this.idUsuario, 'PQR Re abierto.');

        } catch (e) {
            console.error(e);
            this.messageService.add({ severity: 'error', summary: 'Error', detail: 'No se pudo abrir el PQR.' });
        }
    }

    /** Cargar seguimientos por POST (solo idPqrs en FormData) */
    async cargarSeguimientos(idPqrs: number): Promise<IseguimientoPqrs[]> {
        this.loadingSeg = true;
        try {
            const fd = new FormData();
            fd.append('idPqrs', String(idPqrs));

            const resp$ = await this.apiService.post<IseguimientoPqrs[] | { message: string }>(
                'controllers/seguimientospqrs.controller.php?op=todos',
                fd
            );
            const data = await lastValueFrom(resp$);

            if (Array.isArray(data)) {
                this.seguimientos = data.map(s => ({ ...s, adjuntosUrl: this.parseAdjuntos(s?.adjuntosUrl) }));
                console.log('Seguimientos cargados:', this.seguimientos);
                return this.seguimientos;
            }

            // 200 sin array o 404 con {message}
            this.seguimientos = [];
            this.messageService?.add({ severity: 'info', summary: 'Seguimiento', detail:  'Sin registros.' });
            return [];
        } catch (err: any) {
            this.seguimientos = [];
            // this.messageService?.add({ severity: 'error', summary: 'Error', detail: err?.error?.message || 'No se pudo cargar el seguimiento.' });
            return [];
        } finally {
            this.loadingSeg = false;
        }
    }

    sanitizeAndDownscale(html?: string): SafeHtml {
        const raw = html || '';
        const doc = new DOMParser().parseFromString(raw, 'text/html');
        doc.querySelectorAll('img').forEach((img) => {
            img.style.maxWidth = '180px';
            img.style.maxHeight = '160px';
            img.style.width = 'auto';
            img.style.height = 'auto';
            (img.style as any).objectFit = 'contain';
            img.style.display = 'block';
        });
        return this.sanitizer.bypassSecurityTrustHtml(doc.body.innerHTML);
    }

    private parseAdjuntos(adj: string[] | string | null | undefined): string[] {
        if (!adj) return [];
        if (Array.isArray(adj)) return adj.filter(Boolean);
        if (typeof adj === 'string') return adj.split(',').map(s => s.trim()).filter(Boolean);
        return [];
    }

    getEstadoSegSeverity(nombreEstado?: string): 'success' | 'info' | 'warning' | 'danger' {
        const v = (nombreEstado || '').toLowerCase();
        if (v.includes('cerr')) return 'success';
        if (v.includes('escala') || v.includes('rechaz')) return 'danger';
        if (v.includes('pend') || v.includes('abier')) return 'warning';
        return 'info';
    }

    // Orden ascendente para que el chat lea de arriba abajo
    get seguimientosAsc(): IseguimientoPqrs[] {
        return [...this.seguimientos].sort((a, b) => {
            const da = new Date(a?.fechaCreacion || 0).getTime();
            const db = new Date(b?.fechaCreacion || 0).getTime();
            return da - db;
        });
    }

    currentUserLogin?: string;

    isMine(seg: IseguimientoPqrs): boolean {
        if (!this.currentUserLogin) return false;
        return (seg?.usuarioLogin || '').toLowerCase() === this.currentUserLogin.toLowerCase();
    }


    isNewDay(index: number): boolean {
        if (index === 0) return true;
        const curr = this.seguimientosAsc[index]?.fechaCreacion || '';
        const prev = this.seguimientosAsc[index - 1]?.fechaCreacion || '';
        return new Date(curr).toDateString() !== new Date(prev).toDateString();
    }

    private crearFormSeguimiento() {
        this.formSeguimiento = this.fb.group({
            comentario: ['', [Validators.required]],
            cambioEstado: [null], // opcional
        });
    }

    safeHtml(html?: string): SafeHtml {
        return this.sanitizer.bypassSecurityTrustHtml(html || '');
    }

    abrirDialogSeguimiento(): void {
        this.dialogSeguimientoVisible = true;
        this.formSeguimiento.reset({
            comentario: '',
            cambioEstado: null
        });
    }

    confirmarGuardar(): void {
        if (this.formSeguimiento.invalid) {
            this.formSeguimiento.markAllAsTouched();
            return;
        }

        this.confirmationService.confirm({
            header: 'Confirmar',
            message: '¿Deseas agregar este seguimiento?',
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí, guardar',
            rejectLabel: 'No',
            accept: async () => {
                const ok = await this.guardarYRefrescarSeguimientos();
                if (ok) {
                    this.cerrarYLimpiarDialogo();
                }
            }
        });
    }

    confirmarCancelar(): void {
        if (!this.formSeguimiento.dirty) {
            this.cerrarYLimpiarDialogo();
            return;
        }

        this.confirmationService.confirm({
            header: 'Cancelar',
            message: 'Se perderán los cambios no guardados. ¿Deseas continuar?',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, descartar',
            rejectLabel: 'No',
            accept: () => this.cerrarYLimpiarDialogo()
        });
    }

    private cerrarYLimpiarDialogo(): void {
        this.formSeguimiento.reset({
            comentario: '',
            cambioEstado: null
        });
        this.dialogSeguimientoVisible = false;
    }

    private async guardarYRefrescarSeguimientos(): Promise<boolean> {
        try {
            await this.enviarSeguimiento();
            return true;
        } catch {
            return false;
        }
    }

    async enviarSeguimiento() {
        if (this.formSeguimiento.invalid || !this.pqr?.idPqrs) return;

        this.savingSeg = true;
        try {
            const fd = new FormData();
            fd.append('idPqrs', String(this.pqr.idPqrs));
            fd.append('comentario', String(this.formSeguimiento.value.comentario));
            fd.append('idUsuario', String(this.idUsuario));

            const cambioEstado = this.formSeguimiento.value.cambioEstado;
            if (cambioEstado != null) {
                fd.append('cambioEstado', String(cambioEstado));
            }


            const resp = await (
                await this.apiService.post('controllers/seguimientospqrs.controller.php?op=insertar', fd)
            ).toPromise();

            if ((resp as any)?.success === false) {
                this.messageService.add({
                    severity: 'warn',
                    summary: 'Seguimiento',
                    detail: (resp as any)?.message || 'No se pudo agregar el seguimiento.'
                });
                return;
            }

            this.messageService.add({ severity: 'success', summary: 'Seguimiento', detail: 'Seguimiento agregado.' });

            this.formSeguimiento.reset();

            await this.cargarSeguimientos(this.pqr.idPqrs);

            setTimeout(() => {
                const panel = document.querySelector('.chat-panel .p-scrollpanel-content') as HTMLElement;
                panel?.scrollTo({ top: panel.scrollHeight, behavior: 'smooth' });
            }, 50);

        } catch (err: any) {
            console.error(err);
            this.messageService.add({ severity: 'error', summary: 'Error', detail: 'Error al agregar el seguimiento.' });
        } finally {
            this.savingSeg = false;
        }
    }

    quillModules: QuillModules = {
        toolbar: {
            container: [
                ['bold', 'italic', 'underline', 'strike'],
                [{ header: [1, 2, 3, false] }],
                [{ list: 'ordered' }, { list: 'bullet' }],
                [{ align: [] }],
                ['link', 'image'],
                ['clean']
            ],
            handlers: {
                image: () => this.imageAsBase64Handler()
            }
        }
    };

    private imageAsBase64Handler() {
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'image/*';
        input.click();

        input.onchange = async () => {
            const file = input.files?.[0];
            if (!file) return;

            try {
                const dataUrl = await this.fileToDataURL(file);
                const scaled = await this.downscaleDataURLIfNeeded(dataUrl, this.MAX_W, this.MAX_H, this.JPEG_Q);

                const quill = this.quillCmp.quillEditor as Quill;
                const range = quill.getSelection(true);
                const index = range ? range.index : quill.getLength();

                quill.insertEmbed(index, 'image', scaled, 'user');
                quill.setSelection(index + 1, 0, 'silent');
            } catch (e) {
                console.error(e);
                this.messageService.add({ severity: 'error', summary: 'Imagen', detail: 'No se pudo insertar la imagen.' });
            }
        };
    }

    private fileToDataURL(file: File): Promise<string> {
        return new Promise((resolve, reject) => {
            const fr = new FileReader();
            fr.onload = () => resolve(fr.result as string);
            fr.onerror = reject;
            fr.readAsDataURL(file);
        });
    }

    private downscaleDataURLIfNeeded(dataUrl: string, maxW: number, maxH: number, quality = 0.85): Promise<string> {
        return new Promise((resolve) => {
            const img = new Image();
            img.onload = () => {
                const { width, height } = img;
                if (width <= maxW && height <= maxH) return resolve(dataUrl);

                const ratio = Math.min(maxW / width, maxH / height);
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(width * ratio);
                canvas.height = Math.round(height * ratio);

                const ctx = canvas.getContext('2d')!;
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);

                const out = canvas.toDataURL('image/jpeg', quality);
                resolve(out);
            };
            img.onerror = () => resolve(dataUrl);
            img.src = dataUrl;
        });
    }

    private hashCode(str = ''): number {
        let hash = 0;
        for (let i = 0; i < str.length; i++) hash = (hash << 5) - hash + str.charCodeAt(i);
        return Math.abs(hash);
    }

    getUserColor(user?: string): string {
        const h = this.hashCode((user || '').toLowerCase()) % 300;   // tono 0..359
        const s = 55;  // saturación
        const l = 45;  // luminosidad
        return `hsl(${h} ${s}% ${l}%)`;
    }

    private getContrastText(hsl: string): string {
        const m = hsl.match(/hsl\(\s*\d+\s+(\d+)%\s+(\d+)%\s*\)/);
        const l = m ? parseInt(m[2], 10) : 45;
        return l > 55 ? '#1a1a1a' : '#ffffff';
    }

    getAvatarStyle(user?: string): any {
        const bg = this.getUserColor(user);
        const fg = this.getContrastText(bg);
        return {
            background: bg,
            color: fg,
            border: 'none'
        };
    }

    /** (opcional) inicial de usuario para Avatar */
    getInicial(usuario?: string): string {
        if (!usuario) return '?';
        return (usuario.trim()[0] || '?').toUpperCase();
    }

    ngAfterViewInit() {
        const quill = this.quillCmp.quillEditor;
        quill.root.addEventListener('paste', async (ev: ClipboardEvent) => {
            const items = ev.clipboardData?.items || [];
            const fileItem = Array.from(items).find(i => i.type.startsWith('image/'));
            if (!fileItem) return;

            ev.preventDefault();

            const file = fileItem.getAsFile();
            if (!file) return;

            const dataUrl = await this.fileToDataURL(file);
            const slim = await this.downscaleDataURLIfNeeded(dataUrl, this.MAX_W, this.MAX_H, this.JPEG_Q);

            const range = quill.getSelection(true);
            const index = range ? range.index : quill.getLength();
            quill.insertEmbed(index, 'image', slim, 'user');
            quill.setSelection(index + 1, 0, 'silent');
        });
    }

    get isAdmin(): boolean {
        let respuesta = false;
        if (this.idRol === '1') {
            respuesta = true;
        }
        return respuesta;
    }

    get canEscalar(): boolean {
        const estado = (this.pqr?.nombreEstado || '').toUpperCase();
        // return this.isAdmin && estado !== 'CERRADO';
        return estado !== 'CERRADO';
    }

    get canSeguimiento(): boolean {
        let respuesta = true;
        if (this.pqr?.idEstado == 1 || this.pqr?.idEstado == 4) {
            respuesta = false;
        }
        return respuesta;
    }

    // Abrir diálogo: valida y carga responsables
    async abrirEscalar(): Promise<void> {
        if (!this.canEscalar) {
            this.messageService.add({
                severity: 'warn',
                summary: 'Escalar',
                detail: 'No es posible escalar: el PQR está cerrado o no tienes permisos.'
            });
            return;
        }

        this.dialogEscalarVisible = true;
        this.intentoEscalar = false;
        this.responsableSeleccionado = null;

        await this.cargarResponsables(1);
    }

    private async cargarResponsables(soloInactivos: number): Promise<void> {
        try {
            const idPqrs = this.pqr?.idPqrs;
            const url = `controllers/pqrs_responsables.controller.php?op=responsables_por_pqrs&idPqrs=${encodeURIComponent(String(idPqrs))}&soloInactivos=${encodeURIComponent(String(soloInactivos))}`;

            const resp$ = await this.apiService.get<any[]>(url);
            const data: any = await lastValueFrom(resp$);
            const respuesta = data?.data;

            this.responsablesOptions = (Array.isArray(respuesta) ? respuesta : []).map((r: any) => ({
                label: r.nombre || r.usuario || r.login || `${r.nombreCompletoResponsable ?? r.id}`,
                value: r.idResponsable ?? r.id
            }));
        } catch (e) {
            console.error(e);
            this.responsablesOptions = [];
            this.messageService.add({ severity: 'error', summary: 'Escalar', detail: 'No se pudieron cargar los responsables.' });
        }
    }

    confirmarCancelarEscalado(): void {
        this.confirmationService.confirm({
            header: 'Cancelar escalado',
            message: 'Se descartará la selección actual. ¿Deseas continuar?',
            icon: 'pi pi-exclamation-triangle',
            acceptLabel: 'Sí, descartar',
            rejectLabel: 'No',
            accept: () => {
                this.dialogEscalarVisible = false;
                this.responsableSeleccionado = null;
            }
        });
    }

    confirmarEscalar(): void {
        this.intentoEscalar = true;
        if (!this.responsableSeleccionado) return;

        this.confirmationService.confirm({
            header: 'Confirmar escalado',
            message: 'Se asignará el PQR al responsable seleccionado. ¿Deseas continuar?',
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí, escalar',
            rejectLabel: 'No',
            accept: () => this.escalarAsignar()
        });
    }

    private async escalarAsignar(): Promise<void> {
        if (!this.pqr?.idPqrs || !this.responsableSeleccionado) return;

        this.savingEscalar = true;
        try {
            const fd = new FormData();
            fd.append('idPqrs', String(this.pqr.idPqrs));
            fd.append('idResponsable', String(this.responsableSeleccionado));

            const resp = await (
                await this.apiService.post('controllers/pqrs_responsables.controller.php?op=asignar_responsable', fd)
            ).toPromise();

            if ((resp as any)?.success === false) {
                this.messageService.add({
                    severity: 'warn',
                    summary: 'Escalar',
                    detail: (resp as any)?.message || 'No se pudo asignar el responsable.'
                });
                return;
            }

            this.insertarSeguimiento(this.pqr.idPqrs, this.idUsuario, 'PQR escalado.');
            this.messageService.add({ severity: 'success', summary: 'Escalar', detail: 'PQR escalado y responsable asignado.' });

            // Cierra diálogo
            this.dialogEscalarVisible = false;
            this.responsableSeleccionado = null;
            this.esAsignacion = false;

            // Refresca datos:
            await this.cargarPqr(this.pqr.idPqrs);
            await this.cargarSeguimientos(this.pqr.idPqrs);
        } catch (e) {
            console.error(e);
            this.messageService.add({ severity: 'error', summary: 'Escalar', detail: 'Error al escalar el PQR.' });
        } finally {
            this.savingEscalar = false;
        }
    }

    confirmarInicioSeguimiento(rowData: any): void {
        const pqrs = rowData;
        const idPqrs = pqrs?.idPqrs;

        this.confirmationService.confirm({
            header: 'Confirmar inicio de seguimiento',
            message: 'Se dará inicio al seguimiento. ¿Deseas continuar?',
            icon: 'pi pi-question-circle',
            acceptLabel: 'Sí',
            rejectLabel: 'No',
            accept: () => this.iniciarSeguimiento(idPqrs)
        });
    }

    // Iniciar Seguimiento
    iniciarSeguimiento(idPqrs: any): void {
        if (!idPqrs) {
            this.apiService.showToast('error', 'Error', 'ID de PQR inválido.');
            return;
        }
        this.apiService.get<any>(`controllers/pqrs_responsables.controller.php?op=responsable_activo_pqrs&idPqrs=${idPqrs}`).subscribe(
            (resp) => {
                const responsable = resp?.data;
                if (responsable) {
                    this.insertarSeguimiento(idPqrs, responsable.idResponsable, 'Inicio de seguimiento del PQR.');
                }
            });
    }

    async insertarSeguimiento(idPqrs: number, idResponsable: number, comentario: string): Promise<void> {
        const fd = new FormData();
        fd.append('idPqrs', String(idPqrs));
        fd.append('comentario', String(comentario));
        fd.append('idUsuario', String(idResponsable));

        const resp = await (
            await this.apiService.post('controllers/seguimientospqrs.controller.php?op=insertar', fd)
        ).toPromise();
        await this.cargarPqr(idPqrs);
        await this.cargarSeguimientos(idPqrs);

        if ((resp as any)?.success === false) {
            this.messageService.add({
                severity: 'warn',
                summary: 'Seguimiento',
                detail: (resp as any)?.message || 'No se pudo agregar el seguimiento.'
            });
            return;
        }
    }

}
