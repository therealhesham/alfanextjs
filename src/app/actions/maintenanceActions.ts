"use server";

import { prisma } from "@/lib/prisma";

export type MaintenanceContractData = {
  id: string;
  project_name: string | null;
  client_name: string | null;
  technician_name: string | null;
  group_name: string | null;
  start_date: Date | null;
  end_date: Date | null;
  contract_type: string;
  payment_status: string;
  total_amount: number | null;
  is_active: boolean;
  is_guarantee: boolean;
  notes_count: number;
};

export async function getMaintenanceContracts(): Promise<MaintenanceContractData[]> {
  try {
    const contracts = await prisma.maintenance_contracts.findMany({
      include: {
        projects: true,
        users_maintenance_contracts_client_user_idTousers: true,
        users_maintenance_contracts_technician_user_idTousers: true,
        groups: true,
        _count: {
          select: { contract_notes: true },
        },
      },
      orderBy: {
        created_at: "desc",
      },
    });

    return contracts.map((c: any) => ({
      id: c.id.toString(),
      project_name: c.projects?.name || "بدون مشروع",
      client_name: c.users_maintenance_contracts_client_user_idTousers?.name || c.projects?.name || "غير محدد",
      technician_name: c.users_maintenance_contracts_technician_user_idTousers?.name || "غير محدد",
      group_name: c.groups?.name || "بدون مجموعة",
      start_date: c.start_date,
      end_date: c.end_date,
      contract_type: c.contract_type,
      payment_status: c.payment_status,
      total_amount: c.total_amount ? Number(c.total_amount) : null,
      is_active: c.is_active,
      is_guarantee: c.is_guarantee,
      notes_count: c._count.contract_notes,
    }));
  } catch (error) {
    console.error("Error fetching maintenance contracts:", error);
    throw new Error("فشل في جلب عقود الصيانة");
  }
}
