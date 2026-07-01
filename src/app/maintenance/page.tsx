import React from "react";
import { getMaintenanceContracts, getMaintenanceStats } from "@/app/actions/maintenanceActions";
import MaintenanceClient from "./MaintenanceClient";

export const metadata = {
  title: "عقود الصيانة - لوحة التحكم",
};

export default async function MaintenancePage() {
  const [contracts, stats] = await Promise.all([
    getMaintenanceContracts(1, 20, '', false),
    getMaintenanceStats()
  ]);

  return (
    <main className="min-h-screen bg-slate-50/50">
      <MaintenanceClient initialContracts={contracts} initialStats={stats} />
    </main>
  );
}
