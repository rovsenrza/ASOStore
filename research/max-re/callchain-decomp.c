
// ================= 0x100bb0950 =================
// FUN_100bb0950 @ 100bb0950

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */

void FUN_100bb0950(undefined8 *param_1)

{
  long lVar1;
  long unaff_x20;
  
  FUN_100fb0b70();
  FUN_100fae71c(*param_1,FUN_100bad84c,0,0xd00000000000001f,0x8000000102006110,0x17f,
                0xd000000000000018,0x80000001020062c0);
  lVar1 = unaff_x20 + _TtC7OMCalls17CallKitController::delegate;
  _swift_unknownObjectWeakLoadStrong();
  if (lVar1 != 0) {
    FUN_100c212d0();
    _swift_unknownObjectRelease(lVar1);
    return;
  }
  return;
}


// ================= 0x100bb0110 =================
// FUN_100bb0110 @ 100bb0110

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */

void FUN_100bb0110(ID param_1)

{
  UUID UVar1;
  UUID UVar2;
  undefined8 *puVar3;
  undefined *puVar4;
  long extraout_x8;
  long unaff_x20;
  undefined8 *puVar5;
  long lVar6;
  code *pcVar7;
  ID aIStack_90 [6];
  
  UVar1 = Foundation::UUID::typeMetadataAccessor();
  lVar6 = *(long *)(UVar1.unknown + -8);
  (*(code *)PTR____chkstk_darwin_1025898b8)(*(undefined8 *)(lVar6 + 0x40));
  puVar5 = (undefined8 *)((long)aIStack_90 - (extraout_x8 + 0xfU & 0xfffffffffffffff0));
  FUN_101135ee0();
  _swift_beginAccess();
  UVar2.unknown = (undefined *)_objc_msgSend(param_1,PTR_s_callUUID_1028d5fb8);
  Foundation::UUID::__unconditionallyBridgeFromObjectiveC(UVar2);
  FUN_1011380d4(1,3,puVar5);
  pcVar7 = *(code **)(lVar6 + 8);
  puVar3 = puVar5;
  (*pcVar7)(puVar5,UVar1.unknown);
  FUN_100fb0b70();
  aIStack_90[2] = param_1;
  FUN_100fae71c(*puVar3,FUN_100bb0b4c,aIStack_90,0xd00000000000001f,0x8000000102006110,0x14e,
                0xd000000000000014,0x8000000102006310);
  lVar6 = unaff_x20 + _TtC7OMCalls17CallKitController::delegate;
  _swift_unknownObjectWeakLoadStrong();
  if (lVar6 != 0) {
    UVar2.unknown = (undefined *)_objc_msgSend(param_1,PTR_s_callUUID_1028d5fb8);
    Foundation::UUID::__unconditionallyBridgeFromObjectiveC(UVar2);
    puVar4 = &DAT_10260f550;
    _swift_allocObject(&DAT_10260f550,0x18,7);
    _swift_unknownObjectWeakInit(puVar4 + 0x10);
    _swift_retain(puVar4);
    FUN_100c2a2bc(puVar5,lVar6,puVar4,param_1);
    _swift_release(puVar4);
    _swift_unknownObjectRelease(lVar6);
    (*pcVar7)(puVar5,UVar1.unknown);
    _swift_release(puVar4);
  }
  return;
}


// ================= 0x100fae71c =================
// FUN_100fae71c @ 100fae71c

void FUN_100fae71c(void)

{
  FUN_100fae7d4();
  return;
}


// ================= 0x100fb0b70 =================
// FUN_100fb0b70 @ 100fb0b70

undefined * FUN_100fb0b70(void)

{
  if (DAT_102a06340 != -1) {
    _swift_once(&DAT_102a06340,FUN_100fb0b54);
  }
  return &DAT_102b76510;
}


// ================= 0x10001b3dc =================
// FUN_10001b3dc @ 10001b3dc

/* WARNING: Function: _objc_retain replaced with injection: _objc_retain_fixup */
/* WARNING: Function: _objc_release replaced with injection: _objc_release_fixup */
/* WARNING: Function: _objc_retainAutoreleasedReturnValue replaced with injection:
   _objc_retain_fixup */

void FUN_10001b3dc(double param_1,ID param_2,undefined8 param_3,undefined8 param_4,
                  undefined8 param_5)

{
  long lVar1;
  long lVar2;
  bool bVar3;
  Dictionary<undefined,undefined> DVar4;
  undefined *puVar5;
  ID self;
  void *aBlock;
  ulong uVar6;
  undefined **ppuVar7;
  ulong uVar8;
  _StringGuts _Var9;
  undefined8 *puVar10;
  code *pcVar11;
  long unaff_x20;
  undefined8 uVar12;
  ulong uVar13;
  double dVar14;
  long lVar15;
  String SVar16;
  String SVar17;
  undefined *local_a0;
  undefined8 local_98;
  code *local_90;
  undefined *puStack_88;
  undefined8 local_80;
  undefined8 local_78;
  
  SVar16 = (extension_Foundation)::Swift::String::__unconditionallyBridgeFromObjectiveC();
  _Var9.unknown = SVar16.bridgeObject;
  SVar17 = (extension_Foundation)::Swift::String::__unconditionallyBridgeFromObjectiveC();
  puVar10 = SVar17.bridgeObject;
  if (SVar16 == SVar17) {
    _swift_bridgeObjectRelease(_Var9.unknown);
    _swift_bridgeObjectRelease(puVar10);
  }
  else {
    bVar3 = Swift::_stringCompareWithSmolCheck
                      ((_StringGuts)SVar16.str,_Var9,(_StringComparisonResult)(__int8)SVar17.str);
    _swift_bridgeObjectRelease(_Var9.unknown);
    _swift_bridgeObjectRelease();
    if (!bVar3) {
      FUN_100fb0e70();
      (*(code *)PTR____chkstk_darwin_1025898b8)(*puVar10);
      FUN_100fae798();
      return;
    }
  }
  FUN_100faefa0(*(undefined1 *)(unaff_x20 + _TtC3App16VoIPPushRegistry::isAppSceneConnected),
                0xd000000000000010,0x80000001020cead0,FUN_10001995c,0,0xd00000000000001a,
                0x8000000101f74f50,0x6f);
  if (*(char *)(unaff_x20 + _TtC3App16VoIPPushRegistry::isFileSystemUnavailable) != '\x01') {
    dVar14 = *(double *)PTR__kCFAbsoluteTimeIntervalSince1970_102586cc0;
    _CFAbsoluteTimeGetCurrent();
    lVar15 = (long)((dVar14 + param_1) * 1000.0);
    self = unaff_x20 + _TtC3App16VoIPPushRegistry::delegate;
    _swift_unknownObjectWeakLoadStrong();
    lVar2 = _TtC3App16VoIPPushRegistry::pendingVoIPPushes;
    if (self == 0) {
      _swift_beginAccess(unaff_x20 + _TtC3App16VoIPPushRegistry::pendingVoIPPushes,&local_a0,0x21,0)
      ;
      uVar13 = *(ulong *)(unaff_x20 + lVar2);
      _swift_retain(param_5);
      uVar6 = uVar13;
      _swift_isUniquelyReferenced_nonNull_native();
      *(ulong *)(unaff_x20 + lVar2) = uVar13;
      uVar8 = uVar13;
      if ((uVar6 & 1) == 0) {
        uVar8 = 0;
        FUN_10001a670(0,*(long *)(uVar13 + 0x10) + 1,1,uVar13);
        *(ulong *)(unaff_x20 + lVar2) = uVar8;
      }
      uVar6 = *(ulong *)(uVar8 + 0x10);
      uVar13 = uVar8;
      if (*(ulong *)(uVar8 + 0x18) >> 1 <= uVar6) {
        uVar13 = (ulong)(1 < *(ulong *)(uVar8 + 0x18));
        FUN_10001a670(uVar13,uVar6 + 1,1,uVar8);
      }
      *(ulong *)(uVar13 + 0x10) = uVar6 + 1;
      lVar1 = uVar13 + uVar6 * 0x20;
      *(ID *)(lVar1 + 0x20) = param_2;
      *(long *)(lVar1 + 0x28) = lVar15;
      *(undefined8 *)(lVar1 + 0x30) = param_4;
      *(undefined8 *)(lVar1 + 0x38) = param_5;
      *(ulong *)(unaff_x20 + lVar2) = uVar13;
      ppuVar7 = &local_a0;
      _swift_endAccess();
      puVar10 = (undefined8 *)(unaff_x20 + _TtC3App16VoIPPushRegistry::triggerAppBootstrap);
      pcVar11 = (code *)*puVar10;
      if (pcVar11 != (code *)0x0) {
        uVar12 = puVar10[1];
        *puVar10 = 0;
        puVar10[1] = 0;
        FUN_100fb0e70();
        FUN_100fae798(*ppuVar7,FUN_100019abc,0,0xd00000000000001a,0x8000000101f74f50,0x80,
                      0xd00000000000003a,0x8000000101f75010);
        (*pcVar11)(3);
        FUN_100007fe4(pcVar11,uVar12);
      }
    }
    else {
      local_a0 = PTR___NSConcreteStackBlock_102589898;
      local_98 = DAT_1020cddb8;
      local_90 = FUN_100009300;
      puStack_88 = &DAT_1025903d8;
      local_80 = param_4;
      local_78 = param_5;
      aBlock = __Block_copy(&local_a0);
      uVar12 = local_78;
      _swift_retain(param_5);
      _swift_release(uVar12);
      _objc_msgSend(self,PTR_s_didReceiveVoIPPushWithPushPayloa_1028d3a70,lVar15,param_2,aBlock);
      __Block_release(aBlock);
      _swift_unknownObjectRelease(self);
    }
    return;
  }
  FUN_100ca6a60(0);
  _objc_msgSend(param_2,PTR_s_dictionaryPayload_1028e22c8);
  DVar4 = (extension_Foundation)::Swift::Dictionary::__unconditionallyBridgeFromObjectiveC();
  puVar5 = &DAT_102590410;
  _swift_allocObject(&DAT_102590410,0x20,7);
  *(undefined8 *)(puVar5 + 0x10) = param_4;
  *(undefined8 *)(puVar5 + 0x18) = param_5;
  _swift_retain(param_5);
  thunk_FUN_100ca5f48(DVar4.unknown,FUN_10001bb64,puVar5);
  _swift_bridgeObjectRelease(DVar4.unknown);
  _swift_release(puVar5);
  return;
}


// ================= 0x10001bb34 =================
// FUN_10001bb34 @ 10001bb34

void FUN_10001bb34(void)

{
  long unaff_x20;
  
                    /* WARNING: Could not recover jumptable at 0x00010001bb3c. Too many branches */
                    /* WARNING: Treating indirect jump as call */
  (**(code **)(*(long *)(unaff_x20 + 0x10) + 0x10))();
  return;
}

